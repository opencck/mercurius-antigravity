<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

/**
 * How long the steps of `agy` really take on this installation: the last time, the longest one and how many times.
 * The limits of the waits are set from these, not from a guess. Kept in memory only.
 *
 * spec: services/antigravity/BRIEF.md#сессия-agy
 */
final class Timings {
    /** @var array<string, array{last: float, max: float, count: int}> */
    private array $steps = [];

    public function note(string $step, float $seconds): void {
        $known = $this->steps[$step] ?? ['last' => 0.0, 'max' => 0.0, 'count' => 0];
        $seconds = round(max(0.0, $seconds), 2);
        $this->steps[$step] = ['last' => $seconds, 'max' => max($known['max'], $seconds), 'count' => $known['count'] + 1];
    }

    /** @return array<string, array{last: float, max: float, count: int}> */
    public function all(): array {
        return $this->steps;
    }
}
