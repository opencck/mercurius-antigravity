<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Future;
use Amp\Process\Process;
use Amp\Sync\LocalSemaphore;
use Amp\Sync\Lock;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

/**
 * One model call by the plan: one `agy` process, one prompt, one answer. `agy` is the model only. Its own agent is
 * replaced by an agent of the call without built-in tools; the tools of the application are listed to it by an inert
 * MCP server. The first call of a tool of the application ends the call: the process is stopped and the application
 * runs the tool. A step of any other tool, or `agy` falling back to its default agent, is a violation: the process is
 * stopped and the call fails without an answer.
 *
 * The events are what the application needs for a Chat Completions answer: `['text' => string]` as the model writes,
 * `['tool' => ['name' => string, 'arguments' => string]]` once, and `['end' => ['finish' => string, 'usage' => array]]`.
 *
 * spec: services/antigravity/BRIEF.md#один-вызов-модели
 * ref: references/hermes-plugin-antigravity-agy/transport.py@ec07f86:L492-L493 — the command line; L908-L928 — a tool
 *      step is the host's call only when it is call_mcp_tool of our server with an offered name, anything else kills
 *      the process group; L54 — the mark of a fallback to the default agent in the log
 * ref: references/hermes-antigravity-subscription/process.py@6e46b46:L1059-L1124 — `agy` repeats a 429 silently for
 *      minutes: the log is watched for RESOURCE_EXHAUSTED before the first event
 */
final class ModelCall {
    public const AGENT = 'mercurius';
    private const FALLBACK_MARK = 'falling back to default';
    private const STDERR_MAX = 64 * 1024;
    private const LINE_MAX = 16 * 1024 * 1024;
    private const POLL_SECONDS = 2.0;
    /** the kinds of a step that are not a tool and carry nothing to pass on */
    private const QUIET_STEPS = ['user_input', 'error_message'];
    private const NO_USAGE = ['input_tokens' => 0, 'output_tokens' => 0, 'thinking_tokens' => 0, 'cache_read_tokens' => 0];

    private readonly LocalSemaphore $places;

    /**
     * @param string $tmp where the folder of a call is made
     * @param string $inertMcp the path of the inert MCP server
     * @param float $idleSeconds how long `agy` may be silent
     * @param positive-int $concurrent how many `agy` processes run at once
     * @param float $queueSeconds how long a call waits for a place
     */
    public function __construct(
        private readonly AgyCli $cli,
        private readonly ModelList $models,
        private readonly Quota $quota,
        private readonly string $tmp,
        private readonly string $inertMcp,
        private readonly LoggerInterface $logger,
        private readonly float $idleSeconds = 300.0,
        int $concurrent = 2,
        private readonly float $queueSeconds = 120.0,
    ) {
        $this->places = new LocalSemaphore($concurrent);
    }

    /**
     * @return \Generator<int, Event, mixed, void>
     * @throws CallFailed
     * @throws RequestRejected
     * @psalm-suppress InvalidReturnType the generator ends by a plain return
     */
    public function run(ChatRequest $request, ?Cancellation $cancellation = null): \Generator {
        [$model, $level] = $this->models->current()->resolve($request->model, $request->effort);
        $group = Models::quotaGroup($model);
        // a used-up window is known before anything is started: no process, no request to Google
        $closed = $this->quota->closedUntil($group);
        if ($closed !== null) {
            throw self::limit($closed);
        }
        // the semaphore cannot be left while waiting: the wait is a future, and a place that comes after the caller
        // gave up is given back at once
        $waiting = $this->place();
        try {
            $place = $waiting->await(self::both($cancellation, new TimeoutCancellation($this->queueSeconds)));
        } catch (CancelledException $e) {
            $waiting->map(static function (Lock $late): void {
                $late->release();
            })->ignore();
            if ($cancellation?->isRequested() === true) {
                throw $e;
            }
            throw new CallFailed(529, 'overloaded_error', 'Every place for a model call is taken; try again later.');
        }
        $dir = rtrim($this->tmp, '/') . '/antigravity-' . bin2hex(random_bytes(8));
        $process = null;
        $conversation = null;
        try {
            $log = $this->prepare($dir, $request);
            $arguments = ['--input-format', 'stream-json', '--output-format', 'stream-json', '--disable-slash-commands', '--model', $model];
            if ($level !== null) {
                array_push($arguments, '--effort', $level);
            }
            array_push($arguments, '--agent', self::AGENT, '--log-file', $log);
            try {
                $process = Process::start([...Children::session(), ...$this->cli->command(), ...$arguments], $dir . '/workspace', $this->cli->env()->build());
            } catch (Throwable $e) {
                $this->logger->error('antigravity: agy did not start', ['error' => $e::class]);
                throw new CallFailed(503, 'api_error', 'The Antigravity CLI did not start.');
            }
            $stderr = self::tail($process->getStderr());
            [, $prompt] = PromptBuilder::build($request, self::mark($dir));
            try {
                $process->getStdin()->write(json_encode(['event' => 'user', 'message' => ['role' => 'user', 'content' => $prompt]], ChatRequest::JSON) . "\n");
                $process->getStdin()->end();
            } catch (Throwable) {
                // agy went away before it read: what it said decides the error below
            }

            $written = '';
            $usage = self::NO_USAGE;
            $started = false;
            foreach ($this->lines($process, $log, $started, $group, $cancellation) as $event) {
                $kind = Json::string($event, 'event');
                if ($kind === 'init') {
                    $conversation = self::conversationId($event);
                    $agent = Json::string(Json::object($event, 'init'), 'agent');
                    if (self::fellBack($log) || ($agent !== null && $agent !== self::AGENT)) {
                        throw $this->violation('agy did not load the agent of the call and fell back to its default agent');
                    }
                    continue;
                }
                if ($kind === 'step_update') {
                    $step = Json::object($event, 'step_update');
                    $started = true;
                    $conversation ??= self::conversationId($step);
                    $type = Json::string($step, 'step_type');
                    if ($type === 'tool') {
                        $call = self::hostCall($step, $request);
                        if ($call === null) {
                            throw $this->violation('agy tried a tool that is not a tool of the application');
                        }
                        if (Json::string($step, 'state') !== 'ACTIVE') {
                            // our own call that is not started yet: its arguments may still be incomplete
                            continue;
                        }
                        if (self::fellBack($log)) {
                            throw $this->violation('agy did not load the agent of the call and fell back to its default agent');
                        }
                        $usage = self::add($usage, Json::object($step, 'usage'));
                        yield Event::tool($call[0], $call[1]);
                        // malformed arguments are an answer cut short: the application asks again, it runs nothing
                        yield Event::end($call[1] === '' ? 'length' : 'tool_calls', self::usage($usage));
                        return;
                    }
                    if ($type === 'agent_response') {
                        $delta = Json::string($step, 'text_delta') ?? '';
                        if ($delta !== '' && Json::string($step, 'state') !== 'DONE') {
                            // the text of the default agent is never passed on: the log is looked at before the first word
                            if ($written === '' && self::fellBack($log)) {
                                throw $this->violation('agy did not load the agent of the call and fell back to its default agent');
                            }
                            $written .= $delta;
                            yield Event::text($delta);
                        }
                        $usage = self::add($usage, Json::object($step, 'usage'));
                    } elseif (!in_array($type, self::QUIET_STEPS, true)) {
                        // a step of a kind the service does not know may be a tool under another name: the call stops
                        throw $this->violation('agy made a step of an unknown kind');
                    }
                    continue;
                }
                if ($kind === 'result') {
                    $result = Json::object($event, 'result');
                    $conversation ??= self::conversationId($result);
                    if (Json::string($result, 'status') !== 'SUCCESS' || isset($result['error'])) {
                        throw $this->failure((Json::string($result, 'error') ?? '') . "\n" . self::settled($stderr), self::logTail($log), $group);
                    }
                    if (self::fellBack($log)) {
                        throw $this->violation('agy did not load the agent of the call and fell back to its default agent');
                    }
                    $response = Json::string($result, 'response') ?? '';
                    if (strlen($response) > strlen($written) && str_starts_with($response, $written)) {
                        yield Event::text(substr($response, strlen($written)));
                    }
                    $whole = Json::object($result, 'usage');
                    if ($whole !== []) {
                        $usage = self::add(self::NO_USAGE, $whole);
                    }
                    yield Event::end('stop', self::usage($usage));
                    return;
                }
            }
            // the stream ended without a result: what agy said elsewhere tells why
            throw $this->failure(self::settled($stderr), self::logTail($log), $group);
        } finally {
            if ($process !== null) {
                Children::stop($process);
            }
            $this->forget($conversation);
            self::remove($dir);
            $place->release();
        }
    }

    /** @return Future<Lock> */
    private function place(): Future {
        return async($this->places->acquire(...));
    }

    /**
     * The decoded lines of the answer. While nothing came, the log is looked at for a used-up quota, and a silence
     * longer than allowed ends the call.
     *
     * @return \Generator<int, array<array-key, mixed>, mixed, void>
     * @throws CallFailed
     * @psalm-suppress InvalidReturnType the generator ends by a plain return
     */
    private function lines(Process $process, string $log, bool &$started, string $group, ?Cancellation $cancellation): \Generator {
        $stdout = $process->getStdout();
        $buffer = '';
        $silentSince = microtime(true);
        while (true) {
            $chunk = null;
            try {
                $chunk = $stdout->read(self::both($cancellation, new TimeoutCancellation(self::POLL_SECONDS)));
            } catch (CancelledException $e) {
                if ($cancellation?->isRequested() === true) {
                    throw $e;
                }
                if (!$started && str_contains(self::logTail($log), 'RESOURCE_EXHAUSTED')) {
                    throw $this->failure('', self::logTail($log), $group);
                }
                if (microtime(true) - $silentSince > $this->idleSeconds) {
                    throw new CallFailed(504, 'timeout_error', 'The Antigravity CLI was silent for too long.');
                }
                continue;
            }
            if ($chunk === null) {
                return;
            }
            $silentSince = microtime(true);
            $buffer .= $chunk;
            if (strlen($buffer) > self::LINE_MAX) {
                throw new CallFailed(502, 'api_error', 'The Antigravity CLI wrote a line too long to read.');
            }
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 1);
                if ($line === '' || $line[0] !== '{') {
                    continue;
                }
                /** @var mixed $event */
                $event = json_decode($line, true, 64);
                if (is_array($event)) {
                    yield $event;
                }
            }
        }
    }

    /**
     * The call of a tool of the application in a step, or null when the step is anything else.
     *
     * @param array<array-key, mixed> $step
     * @return ?array{string, string} the name and the arguments — a JSON object, empty when `agy` gave none that parse
     */
    private static function hostCall(array $step, ChatRequest $request): ?array {
        $info = Json::object($step, 'tool_info');
        $tool = Json::string($step, 'tool_name') ?? Json::string($info, 'name');
        $parameters = Json::object($info, 'parameters');
        $name = Json::string($parameters, 'ToolName');
        $offered = null;
        foreach ($request->tools as $candidate) {
            if ($candidate['name'] === $name) {
                $offered = $candidate;
            }
        }
        if ($tool !== 'call_mcp_tool' || Json::string($parameters, 'ServerName') !== PromptBuilder::SERVER || $name === null || $offered === null) {
            return null;
        }
        if (!array_key_exists('Arguments', $parameters)) {
            // no arguments at all: an answer cut short for a tool that needs some, an empty object for one that needs none
            $required = Json::object($offered['schema'], 'required');
            return [$name, $required === [] ? '{}' : ''];
        }
        $text = Json::string($parameters, 'Arguments');
        if ($text !== null) {
            /** @var mixed $decoded */
            $decoded = json_decode($text, true, 64);
            return [$name, is_array($decoded) ? self::object($decoded) : ''];
        }
        return [$name, is_array($parameters['Arguments']) ? self::object($parameters['Arguments']) : ''];
    }

    /** @param array<array-key, mixed> $value */
    private static function object(array $value): string {
        return $value === [] ? '{}' : json_encode($value, ChatRequest::JSON);
    }

    /**
     * The folder of a call: the agent with the system text and the list of tools, the manifest for the inert MCP
     * server, the log. Nothing else is in the folder `agy` works in.
     *
     * @return string the path of the log
     * @throws CallFailed
     */
    private function prepare(string $dir, ChatRequest $request): string {
        $agentDir = $dir . '/workspace/.agents/agents/' . self::AGENT;
        if (!@mkdir($agentDir, 0700, true) && !is_dir($agentDir)) {
            throw new CallFailed(503, 'api_error', 'The folder of the call could not be made.');
        }
        @chmod($dir, 0700);
        [$agent] = PromptBuilder::build($request, self::mark($dir));
        $head = "---\nname: " . self::AGENT . "\ndescription: The model of a Mercurius call\nmainAgent: true\nsubagent: false\nexcludeDefaultComponents: true\ninheritMcp: false\n";
        if ($request->tools !== []) {
            $manifest = array_map(static fn(array $tool): array => ['name' => $tool['name'], 'description' => $tool['description'], 'inputSchema' => $tool['schema']], $request->tools);
            $written = file_put_contents($dir . '/tools.json', json_encode($manifest, ChatRequest::JSON));
            if ($written === false) {
                throw new CallFailed(503, 'api_error', 'The folder of the call could not be written.');
            }
            $head .= "mcpServers:\n  - name: " . PromptBuilder::SERVER . "\n    command: " . json_encode(PHP_BINARY, ChatRequest::JSON)
                . "\n    args: " . json_encode([$this->inertMcp, $dir . '/tools.json'], ChatRequest::JSON) . "\n";
        }
        // without the file agy would start its default agent, with its own tools
        if (file_put_contents($agentDir . '/agent.md', $head . "---\n" . $agent . "\n") === false) {
            throw new CallFailed(503, 'api_error', 'The folder of the call could not be written.');
        }
        return $dir . '/agy.log';
    }

    /** The mark of the markup of a call comes from the name of its folder: random and not in the request. */
    private static function mark(string $dir): string {
        return substr($dir, -12);
    }

    /** What `agy` said, as an error of the call. */
    private function failure(string $said, string $log, string $group): CallFailed {
        // the words of the error decide; the log is long and may mention anything, so only one sign is read from it
        $exhausted = str_contains($log, 'RESOURCE_EXHAUSTED');
        $lower = strtolower($said . ($exhausted ? "\n" . $log : ''));
        $has = static function (string ...$needles) use ($lower): bool {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    return true;
                }
            }
            return false;
        };
        if ($has('verify your account', 'terms of service')) {
            return new CallFailed(403, 'account_action_required', 'Google asks the owner of the account to act: open Antigravity and follow what it says. The plan is not called until then.');
        }
        if ($has('not logged into antigravity', 'error getting token source', 'authentication required', 'authentication failed')) {
            return new CallFailed(401, 'authentication_error', 'Nobody is signed in to the Antigravity plan.');
        }
        if ($has('credits balance is too low', 'billing cap', 'daily cap')) {
            return new CallFailed(402, 'billing_error', 'The paid use beyond the plan is spent.');
        }
        if ($exhausted || $has('resource_exhausted', 'quota', 'exhausted your capacity', '(code 429)')) {
            $until = preg_match('~resets in ((?:\d+h)?(?:\d+m)?(?:\d+s)?)~', $lower, $m) === 1 ? self::after($m[1]) : null;
            $until ??= $group === '' ? null : $this->quota->closedUntil($group);
            if ($until !== null && $group !== '') {
                $this->quota->close($group, $until);
            }
            return self::limit($until);
        }
        if ($has('no capacity available', 'overloaded', '(code 503)')) {
            return new CallFailed(529, 'overloaded_error', 'Google has no capacity for this model now.');
        }
        if ($has('requires --effort', 'unknown model', 'invalid model', 'model not found')) {
            return new CallFailed(400, 'invalid_request_error', 'The plan does not take this model.');
        }
        $this->logger->warning('antigravity: agy ended without an answer');
        return new CallFailed(502, 'api_error', 'The Antigravity CLI ended without an answer.');
    }

    /** A used-up window of the plan. The words avoid the ones a client reads as a spent balance. */
    private static function limit(?int $until): CallFailed {
        return new CallFailed(429, 'rate_limit_error', 'The window of the Antigravity plan for this model is used up.', $until);
    }

    /** `1h49m22s` from now, as unix time. */
    private static function after(string $span): ?int {
        if ($span === '' || preg_match('~^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$~', $span, $m) !== 1) {
            return null;
        }
        $seconds = (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
        return $seconds > 0 ? time() + $seconds : null;
    }

    /** A violation is never repeated: a second process would do the same. */
    private function violation(string $what): CallFailed {
        $this->logger->error('antigravity: ' . $what);
        return new CallFailed(422, 'tool_violation', 'The Antigravity CLI left the rules of a model call (' . $what . '); the call was stopped.');
    }

    private static function fellBack(string $log): bool {
        return str_contains(self::logTail($log, 256 * 1024), self::FALLBACK_MARK);
    }

    private static function logTail(string $log, int $bytes = 16 * 1024): string {
        clearstatcache(true, $log);
        $size = @filesize($log);
        if ($size === false || $size === 0) {
            return '';
        }
        $text = @file_get_contents($log, false, null, max(0, $size - $bytes), max(1, $bytes));
        return is_string($text) ? $text : '';
    }

    /** @param array<array-key, mixed> $node */
    private static function conversationId(array $node): ?string {
        $id = Json::string($node, 'conversation_id');
        return $id !== null && preg_match('~^[A-Za-z0-9-]{8,64}$~', $id) === 1 ? $id : null;
    }

    /**
     * @param array{input_tokens: int, output_tokens: int, thinking_tokens: int, cache_read_tokens: int} $total
     * @param array<array-key, mixed> $usage
     * @return array{input_tokens: int, output_tokens: int, thinking_tokens: int, cache_read_tokens: int}
     */
    private static function add(array $total, array $usage): array {
        $count = static fn(string $key): int => max(0, Json::int($usage, $key) ?? 0);
        return [
            'input_tokens' => $total['input_tokens'] + $count('input_tokens'),
            'output_tokens' => $total['output_tokens'] + $count('output_tokens'),
            'thinking_tokens' => $total['thinking_tokens'] + $count('thinking_tokens'),
            'cache_read_tokens' => $total['cache_read_tokens'] + $count('cache_read_tokens'),
        ];
    }

    /**
     * @param array{input_tokens: int, output_tokens: int, thinking_tokens: int, cache_read_tokens: int} $usage
     * @return array<string, mixed> the usage as Chat Completions words it
     */
    private static function usage(array $usage): array {
        $prompt = $usage['input_tokens'] + $usage['cache_read_tokens'];
        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $usage['output_tokens'],
            'total_tokens' => $prompt + $usage['output_tokens'],
            'prompt_tokens_details' => ['cached_tokens' => $usage['cache_read_tokens']],
            'completion_tokens_details' => ['reasoning_tokens' => $usage['thinking_tokens']],
        ];
    }

    /** The record of the conversation `agy` kept is removed: the history lives in the application only. */
    private function forget(?string $conversation): void {
        if ($conversation === null) {
            return;
        }
        $paths = glob($this->cli->dataDir() . '/conversations/' . $conversation . '*');
        foreach ($paths === false ? [] : $paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** @return Future<string> what the stream held last */
    private static function tail(ReadableStream $stream): Future {
        return async(static function () use ($stream): string {
            $kept = '';
            try {
                while (($chunk = $stream->read()) !== null) {
                    $kept = substr($kept . $chunk, -self::STDERR_MAX);
                }
            } catch (Throwable) {
                // the stream went with the child
            }
            return $kept;
        });
    }

    /** @param Future<string> $stderr */
    private static function settled(Future $stderr): string {
        try {
            return $stderr->await(new TimeoutCancellation(5.0));
        } catch (Throwable) {
            return '';
        }
    }

    private static function both(?Cancellation $first, Cancellation $second): Cancellation {
        return $first === null ? $second : new CompositeCancellation($first, $second);
    }

    private static function remove(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
