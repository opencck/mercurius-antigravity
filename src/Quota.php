<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use Throwable;

/**
 * The quota of the plan, asked from `agy` without a model call: groups of models, each with windows that name the
 * share left and the time they reopen. The answer is kept for a minute and asked by one caller at a time; when `agy`
 * does not answer, the last answer serves and is marked as old.
 *
 * spec: services/antigravity/BRIEF.md#квота
 * ref: references/hermes-antigravity-subscription/usage.py@6e46b46:L250-L265 — `agy -p /usage --output-format json`
 *      in a home of the plan, a short timeout; L126-L145 — the first nested object with a list `groups` is the
 *      answer, whatever wraps it; L205-L233 — a bucket: window, remaining_fraction, reset_time
 */
final class Quota {
    private const KEEP_SECONDS = 60.0;
    private const ASK_SECONDS = 15.0;
    private const MAX_DEPTH = 15;

    /** @var ?list<array{name: string, buckets: list<array{window: string, remaining: float, resets_at: ?int}>}> */
    private ?array $groups = null;
    private float $askedAt = 0.0;
    private bool $asking = false;
    private bool $asked = false;
    private bool $stale = false;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param string $workDir an empty folder the question runs in
     * @param (\Closure(): float)|null $clock unix time, for tests
     */
    public function __construct(
        private readonly AgyCli $cli,
        private readonly string $workDir,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * @return array{groups: list<array{name: string, buckets: list<array{window: string, remaining: float, resets_at: ?int}>}>, stale: bool}|null
     *         null — nothing is known: nobody is signed in or `agy` never answered
     */
    public function read(bool $fresh = false): ?array {
        $now = ($this->clock)();
        // an answer that did not come is remembered for the same minute: a silent CLI is not asked before every call
        if (!$this->asking && ($fresh || !$this->asked || $now - $this->askedAt >= self::KEEP_SECONDS)) {
            $this->asking = true;
            $this->asked = true;
            try {
                $groups = $this->ask();
                $this->askedAt = $now;
                if ($groups !== null) {
                    $this->groups = $groups;
                    $this->stale = false;
                } else {
                    $this->stale = $this->groups !== null;
                }
            } finally {
                $this->asking = false;
            }
        }
        return $this->groups === null ? null : ['groups' => $this->groups, 'stale' => $this->stale];
    }

    /**
     * When the group of a model reopens, if one of its windows is used up; null — the model may be called.
     */
    public function closedUntil(string $group): ?int {
        $known = $this->read();
        $now = (int) ($this->clock)();
        $until = null;
        foreach ($known['groups'] ?? [] as $item) {
            if ($item['name'] !== $group) {
                continue;
            }
            foreach ($item['buckets'] as $bucket) {
                if ($bucket['remaining'] <= 0.0 && $bucket['resets_at'] !== null && $bucket['resets_at'] > $now) {
                    $until = max($until ?? 0, $bucket['resets_at']);
                }
            }
        }
        return $until;
    }

    /** What a refused call taught: the group is closed until the time `agy` named. */
    public function close(string $group, int $until): void {
        $closed = ['window' => 'refused', 'remaining' => 0.0, 'resets_at' => $until];
        $groups = [];
        $found = false;
        foreach ($this->groups ?? [] as $item) {
            if ($item['name'] === $group) {
                $item['buckets'][] = $closed;
                $found = true;
            }
            $groups[] = $item;
        }
        if (!$found) {
            $groups[] = ['name' => $group, 'buckets' => [$closed]];
            $this->askedAt = ($this->clock)();
        }
        $this->groups = $groups;
    }

    /** @return ?list<array{name: string, buckets: list<array{window: string, remaining: float, resets_at: ?int}>}> */
    private function ask(): ?array {
        if (!$this->cli->signedIn()) {
            return null;
        }
        try {
            if (!is_dir($this->workDir)) {
                @mkdir($this->workDir, 0700, true);
            }
            [$code, $out] = $this->cli->run(['-p', '/usage', '--output-format', 'json'], $this->workDir, self::ASK_SECONDS);
        } catch (Throwable) {
            return null;
        }
        $start = strpos($out, '{');
        if ($code !== 0 || $start === false) {
            return null;
        }
        /** @var mixed $data */
        $data = json_decode(substr($out, $start), true, 64);
        $groups = is_array($data) ? self::find($data, 0) : null;
        return $groups === null ? null : self::groups($groups);
    }

    /**
     * @param array<array-key, mixed> $node
     * @return ?array<array-key, mixed>
     */
    private static function find(array $node, int $depth): ?array {
        if (isset($node['groups']) && is_array($node['groups']) && array_is_list($node['groups'])) {
            return $node['groups'];
        }
        if ($depth >= self::MAX_DEPTH) {
            return null;
        }
        /** @var mixed $child */
        foreach ($node as $child) {
            $found = is_array($child) ? self::find($child, $depth + 1) : null;
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @return list<array{name: string, buckets: list<array{window: string, remaining: float, resets_at: ?int}>}>
     */
    private static function groups(array $raw): array {
        $groups = [];
        /** @var mixed $group */
        foreach ($raw as $group) {
            $name = is_array($group) ? Json::string($group, 'name') : null;
            if (!is_array($group) || $name === null) {
                continue;
            }
            $buckets = [];
            /** @var mixed $bucket */
            foreach (Json::object($group, 'buckets') as $bucket) {
                if (!is_array($bucket)) {
                    continue;
                }
                /** @psalm-suppress MixedAssignment */
                $left = $bucket['remaining_fraction'] ?? null;
                /** @psalm-suppress MixedAssignment */
                $reset = $bucket['reset_time'] ?? null;
                $time = is_string($reset) ? strtotime($reset) : false;
                $buckets[] = [
                    'window' => substr(Json::string($bucket, 'window') ?? '', 0, 32),
                    'remaining' => is_int($left) || is_float($left) ? max(0.0, min(1.0, (float) $left)) : 1.0,
                    'resets_at' => $time === false ? null : $time,
                ];
            }
            $groups[] = ['name' => substr($name, 0, 128), 'buckets' => $buckets];
        }
        return $groups;
    }
}
