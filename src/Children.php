<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use Amp\Process\Process;
use Throwable;

/**
 * How the service starts and stops `agy`. On Linux every start is a session of its own, so `agy`, its language server
 * and the inert MCP server are stopped together: `agy` leaves helpers behind, and the leader may be gone while they
 * still run — the group is stopped whether the leader is alive or not.
 *
 * ref: references/hermes-plugin-antigravity-agy/transport.py@ec07f86:L262-L268 — a process group of its own, killed
 *      as a group
 *
 * @internal
 */
final class Children {
    /**
     * What goes before the command so that the child leads a session of its own.
     *
     * @return list<string>
     */
    public static function session(): array {
        return PHP_OS_FAMILY === 'Linux' && is_executable('/usr/bin/setsid') && function_exists('posix_kill') ? ['/usr/bin/setsid'] : [];
    }

    public static function stop(Process $process): void {
        try {
            if (self::session() !== []) {
                // the group outlives its leader: it is stopped even when the leader has ended
                @posix_kill(-$process->getPid(), 9);
            }
            if ($process->isRunning()) {
                $process->kill();
            }
        } catch (Throwable) {
            // already gone
        }
    }
}
