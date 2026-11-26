<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

/**
 * The models of the plan as known now: the list of the CLI when the owner is signed in, kept for a quarter of an
 * hour; the pinned table while the CLI does not say.
 *
 * spec: services/antigravity/BRIEF.md#модели
 */
final class ModelList {
    private const KEEP_SECONDS = 900.0;

    private const RETRY_SECONDS = 60.0;

    /** @var ?array{float, Models} */
    private ?array $kept = null;
    private float $failedAt = 0.0;
    private bool $asking = false;

    public function __construct(private readonly AgyCli $cli) {}

    public function current(): Models {
        $now = microtime(true);
        if ($this->kept !== null && $now - $this->kept[0] < self::KEEP_SECONDS) {
            return $this->kept[1];
        }
        // one question at a time, and a CLI that did not answer is left alone for a minute
        if ($this->asking || $now - $this->failedAt < self::RETRY_SECONDS || !$this->cli->signedIn()) {
            return Models::pinned();
        }
        $this->asking = true;
        try {
            $listed = $this->cli->models();
        } finally {
            $this->asking = false;
        }
        if ($listed === null) {
            $this->failedAt = $now;
            return Models::pinned();
        }
        $this->kept = [$now, new Models($listed)];
        return $this->kept[1];
    }

    public function forget(): void {
        $this->kept = null;
        $this->failedAt = 0.0;
    }
}
