<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

/**
 * A terminal for a run of `agy` that needs one. `agy` offers the link of a sign-in only when its input is a terminal;
 * `script` gives the child a pseudo-terminal and carries the bytes both ways. The terminal is wide, so a long link is
 * printed on one line and not broken at the edge.
 *
 * spec: services/antigravity/BRIEF.md#сессия-agy
 *
 * @internal
 */
final class Terminal {
    private const SCRIPT = '/usr/bin/script';
    private const SIZE = 'stty cols 500 rows 50 2>/dev/null; exec ';

    public static function available(): bool {
        return PHP_OS_FAMILY === 'Linux' && is_executable(self::SCRIPT);
    }

    /**
     * @param non-empty-list<string> $command
     * @return non-empty-list<string>
     */
    public static function wrap(array $command): array {
        return [self::SCRIPT, '-qfec', self::SIZE . implode(' ', array_map(escapeshellarg(...), $command)), '/dev/null'];
    }
}
