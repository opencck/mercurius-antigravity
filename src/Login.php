<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use Amp\CancelledException;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Throwable;

use function Amp\async;

/**
 * The sign-in of the owner to the plan, done by `agy` itself. Asked a question in print mode without a sign-in, `agy`
 * prints a link of Google and waits for the code the owner gets after signing in in a browser of their own; the code
 * goes to its input. The service passes the link and the code along and keeps neither. One sign-in at a time; `agy`
 * waits for the code for a minute and then gives up.
 *
 * spec: services/antigravity/BRIEF.md#вход
 */
final class Login {
    /** seconds `agy` waits for the code before it gives up */
    public const WAIT = 60.0;
    private const LINK_SECONDS = 20.0;
    private const DONE_SECONDS = 30.0;
    private const LINK = '~https://accounts\.google\.com/\S+~';

    private ?Process $process = null;
    private float $startedAt = 0.0;

    public function __construct(private readonly AgyCli $cli, private readonly string $workDir) {}

    public function pending(): bool {
        return $this->process !== null && $this->process->isRunning() && microtime(true) - $this->startedAt < self::WAIT;
    }

    /**
     * Starts a sign-in and returns the link the owner opens.
     *
     * @throws LoginFailed
     */
    public function start(): string {
        $this->cancel();
        try {
            if (!is_dir($this->workDir)) {
                @mkdir($this->workDir, 0700, true);
            }
            // any question in print mode asks for the sign-in first; this one costs nothing of the plan
            $process = Process::start([...$this->cli->command(), '-p', '/usage', '--output-format', 'json'], $this->workDir, $this->cli->env()->build());
        } catch (Throwable $e) {
            throw new LoginFailed('The Antigravity CLI did not start: ' . $e::class);
        }
        $this->process = $process;
        $this->startedAt = microtime(true);
        // what agy prints after the link is not needed and never kept
        $drain = async(static function () use ($process): void {
            try {
                while ($process->getStderr()->read() !== null) {
                }
            } catch (Throwable) {
            }
        });
        $drain->ignore();
        $seen = '';
        $wait = new TimeoutCancellation(self::LINK_SECONDS);
        try {
            while (($chunk = $process->getStdout()->read($wait)) !== null) {
                $seen = substr($seen . $chunk, -8192);
                if (preg_match(self::LINK, $seen, $m) === 1 && strpos($seen, "\n", (int) strpos($seen, $m[0])) !== false) {
                    return $m[0];
                }
            }
        } catch (CancelledException) {
            // no link in time
        }
        $this->cancel();
        throw new LoginFailed($this->cli->signedIn() ? 'The plan is signed in already.' : 'The Antigravity CLI gave no link to sign in.');
    }

    /**
     * Gives the code to the waiting `agy`.
     *
     * @return bool whether `agy` is signed in after it
     * @throws LoginFailed when no sign-in waits for a code
     */
    public function code(#[\SensitiveParameter] string $code): bool {
        $process = $this->process;
        if ($process === null || !$this->pending()) {
            $this->cancel();
            throw new LoginFailed('No sign-in waits for a code: start it again.');
        }
        if (preg_match('~^[\x21-\x7e]{4,512}$~', $code) !== 1) {
            throw new LoginFailed('The code is not a code of a sign-in.');
        }
        try {
            $process->getStdin()->write($code . "\n");
            $process->getStdin()->end();
            $process->join(new TimeoutCancellation(self::DONE_SECONDS));
        } catch (Throwable) {
            // agy took too long or went away: what it left decides
        }
        $this->cancel();
        return $this->cli->signedIn();
    }

    public function cancel(): void {
        $process = $this->process;
        $this->process = null;
        try {
            if ($process !== null && $process->isRunning()) {
                $process->kill();
            }
        } catch (Throwable) {
            // already gone
        }
    }
}
