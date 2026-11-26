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

    /** @var ?array{float, Models} */
    private ?array $kept = null;

    public function __construct(private readonly AgyCli $cli) {}

    public function current(): Models {
        $now = microtime(true);
        if ($this->kept !== null && $now - $this->kept[0] < self::KEEP_SECONDS) {
            return $this->kept[1];
        }
        $listed = $this->cli->signedIn() ? $this->cli->models() : null;
        if ($listed === null) {
            // not kept: the next question asks the CLI again
            return Models::pinned();
        }
        $this->kept = [$now, new Models($listed)];
        return $this->kept[1];
    }

    public function forget(): void {
        $this->kept = null;
    }
}
