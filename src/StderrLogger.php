<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use Psr\Log\AbstractLogger;

/**
 * The journal of the service: one JSON line to stderr per record, read with `docker compose logs`. The service logs
 * names and kinds — a host that was refused, the kind of a violation — never a prompt, a code or a token.
 */
final class StderrLogger extends AbstractLogger {
    /** @param resource $stream */
    public function __construct(private $stream) {}

    /**
     * @param mixed $level
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void {
        // the HTTP server narrates every connection at these levels: the journal keeps what somebody must read
        if ($level === 'debug' || $level === 'info') {
            return;
        }
        $line = json_encode(
            ['time' => gmdate('c'), 'level' => is_string($level) ? $level : 'info', 'message' => (string) $message, 'context' => $context],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        if (is_string($line)) {
            @fwrite($this->stream, $line . "\n");
        }
    }
}
