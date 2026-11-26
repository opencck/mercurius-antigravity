<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use Amp\ByteStream\ReadableIterableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * The HTTP side, behind the token of the module. GET /health is open and says what works: the CLI is found and new
 * enough (else 503), and whether the owner is signed in. The rest needs the token as `Authorization: Bearer <token>`:
 * GET /auth/status, POST /auth/login (a link), POST /auth/code, POST /auth/logout; POST /v1/chat/completions is one
 * model call by the plan, answered as the Chat Completions API answers (a stream of events, or one object);
 * GET /v1/models lists the models of the plan; GET /usage is the quota of the plan, asked from the CLI without a
 * model call.
 *
 * spec: services/antigravity/BRIEF.md#http-интерфейс
 */
final class Server {
    /** seconds an answer of the CLI about itself is reused: a probe starts a process */
    private const STATUS_SECONDS = 30.0;
    private const MAX_BODY = 4096;
    private const MAX_REQUEST = 32 * 1024 * 1024;
    private const ROUTES = ['GET /auth/status', 'POST /auth/login', 'POST /auth/code', 'POST /auth/logout', 'POST /v1/chat/completions', 'GET /v1/models', 'GET /usage'];

    /** @var ?array{float, CliStatus} */
    private ?array $status = null;
    public function __construct(
        private readonly AgyCli $cli,
        private readonly Login $login,
        private readonly ModelCall $calls,
        private readonly Quota $quota,
        private readonly ModelList $models,
        #[SensitiveParameter] private readonly string $token,
        private readonly LoggerInterface $logger,
        private readonly string $route = 'direct',
    ) {}

    public function respond(Request $request): Response {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        if ($path === '/health' && $method === 'GET') {
            $status = $this->status();
            return self::json($status->usable() ? HttpStatus::OK : HttpStatus::SERVICE_UNAVAILABLE, [
                'status' => $status->usable() ? 'ok' : 'unavailable',
                'module' => 'antigravity',
                'cli' => $status->version,
                'auth' => $status->auth,
                'route' => $this->route,
                'detail' => $status->detail,
            ]);
        }
        $route = $method . ' ' . $path;
        if (!in_array($route, self::ROUTES, true)) {
            return new Response(HttpStatus::NOT_FOUND, [], '');
        }
        if (!hash_equals('Bearer ' . $this->token, trim($request->getHeader('authorization') ?? ''))) {
            return new Response(HttpStatus::UNAUTHORIZED, [], '');
        }
        try {
            return match ($route) {
                'GET /auth/status' => $this->authStatus(),
                'POST /auth/login' => $this->loginStart(),
                'POST /auth/code' => $this->loginCode($request),
                'POST /auth/logout' => self::error(HttpStatus::NOT_IMPLEMENTED, 'not_implemented', 'The Antigravity CLI has no command to sign out; the owner signs out in the settings of the Google account, or removes the data folder of the module.'),
                'POST /v1/chat/completions' => $this->completions($request),
                'GET /usage' => $this->usage(),
                default => self::json(HttpStatus::OK, ['object' => 'list', 'data' => array_map(
                    static fn(array $model): array => ['id' => $model['id'], 'object' => 'model', 'owned_by' => 'antigravity', 'efforts' => $model['efforts'], 'context_window' => $model['context_window'], 'input' => $model['input'], 'quota_group' => $model['quota_group']],
                    $this->models->current()->offered(),
                )]),
            };
        } catch (Throwable $e) {
            $this->logger->error('antigravity failed', ['route' => $route, 'error' => $e::class]);
            return self::error(HttpStatus::INTERNAL_SERVER_ERROR, 'api_error', 'internal error');
        }
    }

    /** One model call. Without a sign-in nothing is started: the answer says to sign in. */
    private function completions(Request $request): Response {
        $status = $this->status();
        if (!$status->usable()) {
            return self::error(HttpStatus::SERVICE_UNAVAILABLE, 'api_error', $status->detail);
        }
        if (!$status->signedIn()) {
            return self::error(HttpStatus::UNAUTHORIZED, 'authentication_error', $status->detail);
        }
        $request->getBody()->increaseSizeLimit(self::MAX_REQUEST);
        // the application went away: the process of its call is stopped, the plan is not spent for nothing
        $gone = new DeferredCancellation();
        $request->getClient()->onClose($gone->cancel(...));
        try {
            $call = ChatRequest::parse($request->getBody()->buffer(limit: self::MAX_REQUEST));
            $events = $this->calls->run($call, $gone->getCancellation());
            // the first event is waited for here: an error before it is an HTTP error, not a broken stream; the
            // generator is not moved past it, so it is read again from this event
            $events->current();
        } catch (RequestRejected $e) {
            return self::error(HttpStatus::BAD_REQUEST, 'invalid_request_error', $e->getMessage());
        } catch (CallFailed $e) {
            return $this->failed($e);
        } catch (CancelledException) {
            return self::error(HttpStatus::BAD_REQUEST, 'invalid_request_error', 'The request was cancelled.');
        }
        $id = 'chatcmpl-' . bin2hex(random_bytes(12));
        if ($call->stream) {
            return new Response(HttpStatus::OK, ['content-type' => 'text/event-stream', 'cache-control' => 'no-cache'], new ReadableIterableStream(self::chunks($events, $id, $call, $gone)));
        }
        try {
            return self::json(HttpStatus::OK, self::whole($events, $id, $call));
        } catch (CallFailed $e) {
            return $this->failed($e);
        } catch (CancelledException) {
            return self::error(HttpStatus::BAD_REQUEST, 'invalid_request_error', 'The request was cancelled.');
        }
    }

    private function failed(CallFailed $e): Response {
        if ($e->status === HttpStatus::UNAUTHORIZED) {
            // the sign-in is gone: the next status must not come from the cache
            $this->status = null;
        }
        return new Response($e->status, ['content-type' => 'application/json', ...$e->headers(time())], json_encode($e->body(), ChatRequest::JSON));
    }

    /**
     * The answer as a stream of Chat Completions chunks. An error after the first chunk goes as an error chunk: the
     * client ends its stream with it.
     *
     * @param \Generator<int, Event, mixed, void> $events
     * @param DeferredCancellation $gone lives as long as the stream: destroyed, it would cancel the call
     * @return \Generator<int, string, mixed, void>
     */
    private static function chunks(\Generator $events, string $id, ChatRequest $call, DeferredCancellation $gone): \Generator {
        $chunk = static fn(array $delta, ?string $finish = null): string => 'data: ' . json_encode([
            'id' => $id, 'object' => 'chat.completion.chunk', 'model' => $call->model,
            'choices' => [['index' => 0, 'delta' => $delta === [] ? new \stdClass() : $delta, 'finish_reason' => $finish]],
        ], ChatRequest::JSON) . "\n\n";
        try {
            yield $chunk(['role' => 'assistant'] + ($call->mediaOmitted > 0 ? ['content' => ''] : []));
            foreach ($events as $event) {
                if ($event->text !== null) {
                    yield $chunk(['content' => $event->text]);
                } elseif ($event->toolName !== null) {
                    yield $chunk(['tool_calls' => [['index' => 0, 'id' => 'call_' . bin2hex(random_bytes(8)), 'type' => 'function', 'function' => ['name' => $event->toolName, 'arguments' => $event->toolArguments]]]]);
                } elseif ($event->finish !== null) {
                    yield $chunk([], $event->finish);
                    yield 'data: ' . json_encode(['id' => $id, 'object' => 'chat.completion.chunk', 'model' => $call->model, 'choices' => [], 'usage' => $event->usage], ChatRequest::JSON) . "\n\n";
                }
            }
            yield "data: [DONE]\n\n";
        } catch (CallFailed $e) {
            yield 'data: ' . json_encode($e->body(), ChatRequest::JSON) . "\n\n";
        } catch (CancelledException) {
            // the application went away
        } finally {
            unset($gone);
        }
    }

    /**
     * @param \Generator<int, Event, mixed, void> $events
     * @return array<string, mixed>
     * @throws CallFailed
     */
    private static function whole(\Generator $events, string $id, ChatRequest $call): array {
        $text = '';
        $calls = [];
        $finish = 'stop';
        $usage = [];
        foreach ($events as $event) {
            if ($event->text !== null) {
                $text .= $event->text;
            } elseif ($event->toolName !== null) {
                $calls[] = ['id' => 'call_' . bin2hex(random_bytes(8)), 'type' => 'function', 'function' => ['name' => $event->toolName, 'arguments' => $event->toolArguments]];
            } elseif ($event->finish !== null) {
                $finish = $event->finish;
                $usage = $event->usage;
            }
        }
        $message = ['role' => 'assistant', 'content' => $text === '' && $calls !== [] ? null : $text];
        if ($calls !== []) {
            $message['tool_calls'] = $calls;
        }
        return ['id' => $id, 'object' => 'chat.completion', 'model' => $call->model, 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]], 'usage' => $usage];
    }

    private function usage(): Response {
        $known = $this->quota->read(true);
        return $known === null
            ? self::error(HttpStatus::SERVICE_UNAVAILABLE, 'api_error', 'The quota of the plan is not known: nobody is signed in, or the CLI did not answer.')
            : self::json(HttpStatus::OK, $known);
    }

    private function authStatus(): Response {
        $status = $this->status();
        return self::json(HttpStatus::OK, ['cli' => $status->version, 'usable' => $status->usable(), 'auth' => $status->auth, 'detail' => $status->detail, 'login_pending' => $this->login->pending()]);
    }

    private function loginStart(): Response {
        try {
            return self::json(HttpStatus::OK, ['url' => $this->login->start(), 'wait_seconds' => (int) Login::WAIT]);
        } catch (LoginFailed $e) {
            return self::error(HttpStatus::SERVICE_UNAVAILABLE, 'api_error', $e->getMessage());
        }
    }

    private function loginCode(Request $request): Response {
        /** @var mixed $body */
        $body = json_decode($request->getBody()->buffer(limit: self::MAX_BODY), true);
        /** @var mixed $given */
        $given = is_array($body) ? ($body['code'] ?? null) : null;
        $code = is_string($given) ? trim($given) : '';
        if ($code === '') {
            return self::error(HttpStatus::BAD_REQUEST, 'invalid_request_error', 'code is required');
        }
        try {
            $taken = $this->login->code($code);
        } catch (LoginFailed $e) {
            return self::error(HttpStatus::CONFLICT, 'invalid_request_error', $e->getMessage());
        }
        $this->status = null;
        $this->models->forget();
        return $taken
            ? self::json(HttpStatus::OK, ['auth' => $this->status()->auth])
            : self::error(HttpStatus::UNPROCESSABLE_ENTITY, 'authentication_error', 'The Antigravity CLI did not take the code: start the sign-in again.');
    }

    private function status(): CliStatus {
        $now = microtime(true);
        if ($this->status !== null && $now - $this->status[0] < self::STATUS_SECONDS) {
            return $this->status[1];
        }
        $status = $this->cli->status();
        $this->status = [$now, $status];
        return $status;
    }

    /** @param int<1, 65535> $port */
    public function start(string $host, int $port): SocketHttpServer {
        // a model may be silent for minutes while it thinks, and a stream must not be held back for compression
        $server = SocketHttpServer::createForDirectAccess(
            $this->logger,
            enableCompression: false,
            connectionLimit: 64,
            connectionLimitPerIp: 64,
            httpDriverFactory: new DefaultHttpDriverFactory($this->logger, streamTimeout: 900, connectionTimeout: 900, bodySizeLimit: self::MAX_REQUEST),
        );
        $server->expose(new InternetAddress($host, $port));
        $server->start(new ClosureRequestHandler($this->respond(...)), new DefaultErrorHandler());
        return $server;
    }

    private static function error(int $status, string $type, string $message): Response {
        return self::json($status, ['error' => ['message' => $message, 'type' => $type, 'code' => $status]]);
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): Response {
        return new Response($status, ['content-type' => 'application/json'], json_encode($data, ChatRequest::JSON));
    }
}
