<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

/**
 * The models of the plan and how a request names one for `agy`. A model `agy` lists as `<base>-low|medium|high|xhigh`
 * takes an effort; a model listed by a bare name does not. The application asks either for a listed name or for a
 * base with an effort of its own, and the service picks the level the model has.
 *
 * The window is not the window of the model: the whole conversation goes to `agy` as one message, and `agy` cuts a
 * long message silently, so the window offered to the application is what one message may hold.
 *
 * spec: services/antigravity/BRIEF.md#модели
 * ref: references/hermes-antigravity-subscription/models.py@6e46b46:L15-L33 — the rule of levels and the table kept for
 *      a failed `agy models`; L122-L134, L288-L294 — an asked level the model lacks becomes the nearest, the stronger
 *      at a tie
 * ref: references/hermes-antigravity-subscription/__init__.py@6e46b46:L44-L66 — a Gemini model is cut at one long
 *      message, a Claude one was not seen cut; the plugin offers 75 000 tokens without its own splitting
 */
final class Models {
    public const ID = '~^[a-z0-9][a-z0-9.\-]{0,63}$~';
    private const LEVELS = ['low', 'medium', 'high', 'xhigh'];
    /** tokens one message to a Gemini model may hold before `agy` cuts it; below the 65–69 thousand seen cut */
    private const GEMINI_MESSAGE = 60_000;
    /** tokens one message to another model may hold: messages of 122 thousand were seen to pass whole */
    private const OTHER_MESSAGE = 120_000;
    /** the table for the time `agy models` does not answer */
    private const PINNED = [
        'gemini-4-hydrogen-low', 'gemini-4-hydrogen-medium', 'gemini-4-hydrogen-high', 'gemini-4-hydrogen-xhigh',
        'gemini-3.8-flash-low', 'gemini-3.8-flash-medium', 'gemini-3.8-flash-high',
        'gemini-3.1-pro-low', 'gemini-3.1-pro-high',
        'gpt-oss-120b-medium', 'claude-sonnet-4-6', 'claude-opus-4-6-thinking',
    ];

    /** @var array<string, list<string>> base => the levels it has; an empty list — the model takes no effort */
    private readonly array $families;

    /** @param list<string> $listed the names as `agy models` gives them */
    public function __construct(array $listed) {
        $families = [];
        foreach ($listed as $id) {
            $id = strtolower(trim($id));
            if (preg_match(self::ID, $id) !== 1) {
                continue;
            }
            if (preg_match('~^(.+)-(low|medium|high|xhigh)$~', $id, $m) === 1) {
                $families[$m[1]][] = $m[2];
            } else {
                $families[$id] ??= [];
            }
        }
        foreach ($families as $base => $levels) {
            $families[$base] = array_values(array_intersect(self::LEVELS, $levels));
        }
        $this->families = $families;
    }

    public static function pinned(): self {
        return new self(self::PINNED);
    }

    /**
     * What the application may ask for: a model by its base name.
     *
     * @return list<array{id: string, efforts: list<string>, context_window: int, input: list<string>, quota_group: string}>
     */
    public function offered(): array {
        $out = [];
        foreach ($this->families as $base => $levels) {
            $out[] = ['id' => $base, 'efforts' => $levels, 'context_window' => self::window($base), 'input' => self::input($base), 'quota_group' => self::quotaGroup($base)];
        }
        return $out;
    }

    /**
     * The name and the effort `agy` is started with.
     *
     * @return array{string, ?string} the model for `--model` and the level for `--effort` (null — the model takes none)
     * @throws RequestRejected
     */
    public function resolve(string $model, ?string $effort): array {
        $model = strtolower(trim($model));
        if (preg_match(self::ID, $model) !== 1) {
            throw new RequestRejected('model: not a name of a model');
        }
        $asked = null;
        if (!isset($this->families[$model]) && preg_match('~^(.+)-(low|medium|high|xhigh)$~', $model, $m) === 1) {
            // a listed name with its level: the level in the name wins over the effort of the request
            [$model, $asked] = [$m[1], $m[2]];
        }
        if (!isset($this->families[$model])) {
            throw new RequestRejected(sprintf('model: the plan has no model %s; it has: %s', $model, implode(', ', array_keys($this->families))));
        }
        $levels = $this->families[$model];
        if ($levels === []) {
            return [$model, null];
        }
        $level = self::nearest($asked ?? self::level($effort), $levels);
        return [$model . '-' . $level, $level];
    }

    /** The group of the plan's quota a model is counted in, as `agy` names the groups. */
    public static function quotaGroup(string $model): string {
        return str_starts_with(strtolower($model), 'gemini') ? 'Gemini Models' : 'Claude and GPT models';
    }

    private static function window(string $base): int {
        return str_starts_with($base, 'gemini') ? self::GEMINI_MESSAGE : self::OTHER_MESSAGE;
    }

    /** @return list<string> */
    private static function input(string $base): array {
        // images and video reach a model through a file `agy` reads; the service does not pass them yet
        return ['text'];
    }

    /** The effort of a request as a level of `agy`. */
    private static function level(?string $effort): string {
        return match (strtolower(trim((string) $effort))) {
            'none', 'off', 'minimal', 'low' => 'low',
            'high' => 'high',
            'xhigh', 'max' => 'xhigh',
            default => 'medium',
        };
    }

    /**
     * @param non-empty-list<string> $levels
     */
    private static function nearest(string $wanted, array $levels): string {
        if (in_array($wanted, $levels, true)) {
            return $wanted;
        }
        $rank = array_flip(self::LEVELS);
        $best = $levels[0];
        foreach ($levels as $level) {
            $distance = abs($rank[$level] - $rank[$wanted]);
            $bestDistance = abs($rank[$best] - $rank[$wanted]);
            if ($distance < $bestDistance || ($distance === $bestDistance && $rank[$level] > $rank[$best])) {
                $best = $level;
            }
        }
        return $best;
    }
}
