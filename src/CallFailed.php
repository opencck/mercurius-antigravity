<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use RuntimeException;

/**
 * A model call that gave no answer, with the HTTP status the application gets and the type it reads.
 *
 * spec: services/antigravity/BRIEF.md#ошибки
 */
final class CallFailed extends RuntimeException {
    /**
     * @param int $status the HTTP status for the application
     * @param string $type the kind of the error, in words of the service
     * @param ?int $resetsAt unix time a used-up window of the plan reopens
     */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        string $message,
        public readonly ?int $resetsAt = null,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> the body of the answer, as the Chat Completions API words an error */
    public function body(): array {
        $error = ['message' => $this->getMessage(), 'type' => $this->type, 'code' => $this->status];
        if ($this->resetsAt !== null) {
            $error['resets_at'] = $this->resetsAt;
        }
        return ['error' => $error];
    }

    /** @return array<non-empty-string, string> */
    public function headers(int $now): array {
        return $this->resetsAt === null ? [] : ['retry-after' => (string) max(1, $this->resetsAt - $now)];
    }
}
