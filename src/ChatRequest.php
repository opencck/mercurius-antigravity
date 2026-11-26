<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

/**
 * A Chat Completions request of the application, checked and reduced to what `agy` can be given: a model, an effort,
 * the messages as text with tool calls and tool results, and the tools. `agy` has no temperature and no limit of the
 * answer: those are taken and ignored. A field the service does not know is refused, so nothing is dropped silently.
 *
 * spec: services/antigravity/BRIEF.md#запрос
 */
final class ChatRequest {
    public const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    /** stands in for a picture or a video: `agy` takes text only on this way in */
    public const MEDIA_OMITTED = '[media omitted: this model route takes text only]';
    private const KNOWN = ['model', 'messages', 'tools', 'tool_choice', 'reasoning_effort', 'reasoning', 'stream', 'stream_options', 'temperature', 'max_tokens', 'max_completion_tokens', 'extra_body', 'n', 'parallel_tool_calls'];
    private const EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];
    private const MAX_MESSAGES = 5000;
    private const MAX_TOOLS = 512;
    private const NAME = '~^[A-Za-z0-9_.:-]{1,128}$~';

    /**
     * @param list<array{role: 'system'|'user'|'assistant'|'tool', text: string, calls: list<array{id: string, name: string, arguments: string}>, call_id: string}> $messages
     * @param list<array{name: string, description: string, schema: array<string, mixed>}> $tools
     */
    private function __construct(
        public readonly string $model,
        public readonly ?string $effort,
        public readonly array $messages,
        public readonly array $tools,
        public readonly bool $stream,
        /** how many pictures and videos were left out */
        public readonly int $mediaOmitted,
    ) {}

    /** @throws RequestRejected */
    public static function parse(string $body): self {
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RequestRejected('the body is not JSON');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RequestRejected('the body is not a JSON object');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KNOWN, true)) {
                throw new RequestRejected(sprintf('%s: a field this service does not take', (string) $key));
            }
        }
        $model = self::str($data, 'model');
        if ($model === null || preg_match(Models::ID, strtolower($model)) !== 1) {
            throw new RequestRejected('model: a name of a model is needed');
        }
        if (isset($data['n']) && $data['n'] !== 1) {
            throw new RequestRejected('n: only one answer');
        }
        $effort = self::str($data, 'reasoning_effort');
        /** @psalm-suppress MixedAssignment */
        $reasoning = $data['reasoning'] ?? null;
        if ($effort === null && is_array($reasoning)) {
            $effort = self::str($reasoning, 'effort');
        }
        if ($effort !== null && !in_array($effort, self::EFFORTS, true)) {
            throw new RequestRejected('reasoning_effort: one of ' . implode(', ', self::EFFORTS));
        }
        $choice = $data['tool_choice'] ?? 'auto';
        if (!in_array($choice, ['auto', 'none', null], true)) {
            throw new RequestRejected('tool_choice: only auto and none');
        }
        $tools = $choice === 'none' ? [] : self::tools($data['tools'] ?? []);
        $omitted = 0;
        $messages = self::messages($data['messages'] ?? null, $omitted);
        return new self(strtolower($model), $effort, $messages, $tools, ($data['stream'] ?? false) === true, $omitted);
    }

    /** @return list<string> */
    public function toolNames(): array {
        return array_map(static fn(array $tool): string => $tool['name'], $this->tools);
    }

    /**
     * @return list<array{name: string, description: string, schema: array<string, mixed>}>
     * @throws RequestRejected
     */
    private static function tools(mixed $tools): array {
        if (!is_array($tools) || count($tools) > self::MAX_TOOLS) {
            throw new RequestRejected('tools: a list of up to ' . self::MAX_TOOLS . ' tools');
        }
        $out = [];
        $seen = [];
        /** @var mixed $tool */
        foreach ($tools as $tool) {
            /** @psalm-suppress MixedAssignment */
            $function = is_array($tool) && ($tool['type'] ?? null) === 'function' ? ($tool['function'] ?? null) : null;
            if (!is_array($function)) {
                throw new RequestRejected('tools: only function tools');
            }
            $name = self::str($function, 'name');
            if ($name === null || preg_match(self::NAME, $name) !== 1 || isset($seen[$name])) {
                throw new RequestRejected('tools: every tool needs a name of its own');
            }
            $seen[$name] = true;
            /** @psalm-suppress MixedAssignment */
            $parameters = $function['parameters'] ?? [];
            $out[] = ['name' => $name, 'description' => self::str($function, 'description') ?? '', 'schema' => self::schema(is_array($parameters) ? $parameters : [])];
        }
        return $out;
    }

    /**
     * `agy` refuses a tool whose input is not a schema of an object: the top level is made one, and what cannot stand
     * at the top (a union) is dropped there.
     *
     * ref: references/hermes-plugin-antigravity-agy/transport.py@ec07f86:L189-L204 (normalize_schema)
     *
     * @param array<array-key, mixed> $schema
     * @return array<string, mixed>
     */
    private static function schema(array $schema): array {
        $out = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($schema as $key => $value) {
            if (is_string($key) && !in_array($key, ['oneOf', 'allOf', 'anyOf'], true)) {
                $out[$key] = $value;
            }
        }
        $out['type'] = 'object';
        if (!isset($out['properties']) || !is_array($out['properties']) || $out['properties'] === []) {
            $out['properties'] = new \stdClass();
        }
        return $out;
    }

    /**
     * @return list<array{role: 'system'|'user'|'assistant'|'tool', text: string, calls: list<array{id: string, name: string, arguments: string}>, call_id: string}>
     * @throws RequestRejected
     */
    private static function messages(mixed $messages, int &$omitted): array {
        if (!is_array($messages) || $messages === [] || count($messages) > self::MAX_MESSAGES) {
            throw new RequestRejected('messages: a list of messages is needed');
        }
        $out = [];
        /** @var mixed $message */
        foreach ($messages as $message) {
            if (!is_array($message)) {
                throw new RequestRejected('messages: every message is an object');
            }
            $role = match ($message['role'] ?? null) {
                'system', 'developer' => 'system',
                'user' => 'user',
                'assistant' => 'assistant',
                'tool' => 'tool',
                default => throw new RequestRejected('messages: a role of system, user, assistant or tool'),
            };
            $calls = [];
            /** @psalm-suppress MixedAssignment */
            $toolCalls = $message['tool_calls'] ?? [];
            /** @var mixed $call */
            foreach ($role === 'assistant' && is_array($toolCalls) ? $toolCalls : [] as $call) {
                /** @psalm-suppress MixedAssignment */
                $function = is_array($call) ? ($call['function'] ?? null) : null;
                $name = is_array($function) ? self::str($function, 'name') : null;
                $id = is_array($call) ? self::str($call, 'id') : null;
                if (!is_array($function) || $name === null || preg_match(self::NAME, $name) !== 1 || $id === null) {
                    throw new RequestRejected('messages: a tool call needs an id and the name of a function');
                }
                $calls[] = ['id' => self::token($id), 'name' => $name, 'arguments' => self::str($function, 'arguments') ?? '{}'];
            }
            $callId = $role === 'tool' ? self::token(self::str($message, 'tool_call_id') ?? '') : '';
            $out[] = ['role' => $role, 'text' => self::text($message['content'] ?? '', $omitted), 'calls' => $calls, 'call_id' => $callId];
        }
        $last = $out[count($out) - 1]['role'];
        if ($last !== 'user' && $last !== 'tool') {
            throw new RequestRejected('messages: the last message is of the user or of a tool');
        }
        return $out;
    }

    /** The text of a content: a string, or the text parts of a list; a picture or a video is named as left out. */
    private static function text(mixed $content, int &$omitted): string {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        /** @var mixed $part */
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = self::str($part, 'type');
            if ($type === 'text' || $type === 'input_text') {
                $parts[] = self::str($part, 'text') ?? '';
            } elseif ($type !== null) {
                $omitted++;
                $parts[] = self::MEDIA_OMITTED;
            }
        }
        return implode("\n", $parts);
    }

    /** An id as it goes into the markup of the prompt: its safe characters only. */
    private static function token(string $id): string {
        return substr(preg_replace('~[^A-Za-z0-9_.:-]~', '_', $id) ?? '', 0, 128);
    }

    /** @param array<array-key, mixed> $data */
    private static function str(array $data, string $key): ?string {
        /** @psalm-suppress MixedAssignment */
        $value = $data[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
