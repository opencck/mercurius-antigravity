<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Throwable;

use function Amp\async;

/**
 * One run of `agy` in which it talks the way it talks to a person: it prints, waits, and reads a line. The service
 * drives such a run step by step. A step waits until `agy` has printed what the step is about, each wait has its own
 * limit, and a line is sent only after the words it answers were seen — a line sent earlier is lost or read as
 * something else. What `agy` prints is read as text: the control sequences of a terminal are taken out. Nothing of it
 * is kept beyond the run.
 *
 * The run is under a terminal when `agy` needs one and over plain pipes otherwise; the steps are the same.
 *
 * spec: services/antigravity/BRIEF.md#сессия-agy
 */
final class Session {
    private const KEPT = 16 * 1024;
    private const SEQUENCE = '\x1b(?:\[[0-?]*[ -/]*[@-~]|\][^\x07\x1b]*(?:\x07|\x1b\\\\)|[@-Z\\\\^_])';

    private string $screen = '';
    private bool $ended = false;
    /** @var ?DeferredFuture<null> */
    private ?DeferredFuture $news = null;
    private readonly float $startedAt;

    private function __construct(private readonly Process $process, public readonly bool $terminal) {
        $this->startedAt = microtime(true);
        $this->pump();
    }

    /**
     * @param non-empty-list<string> $command
     * @param array<string, string> $env
     * @param bool $terminal whether the run wants a terminal; it gets one where the service can give it
     * @throws Throwable when the child did not start
     */
    public static function start(array $command, string $cwd, array $env, bool $terminal = false): self {
        $terminal = $terminal && Terminal::available();
        $process = Process::start([...Children::session(), ...($terminal ? Terminal::wrap($command) : $command)], $cwd, $env);
        return new self($process, $terminal);
    }

    /**
     * Waits for the first of the patterns in what `agy` printed since the last step.
     *
     * @param non-empty-array<string, non-empty-string> $patterns a name of an outcome to its pattern
     * @return ?array{string, list<string>} the name and the groups of the pattern that came first; null when `agy`
     *                                      ended or the time is out
     */
    public function expect(array $patterns, float $seconds): ?array {
        $deadline = microtime(true) + $seconds;
        while (true) {
            $best = null;
            foreach ($patterns as $name => $pattern) {
                if (preg_match($pattern, $this->screen, $m, PREG_OFFSET_CAPTURE) === 1 && ($best === null || $m[0][1] < $best[2])) {
                    $best = [$name, array_column($m, 0), $m[0][1], $m[0][1] + strlen($m[0][0])];
                }
            }
            if ($best !== null) {
                // what a step has read is not read by the next one
                $this->screen = substr($this->screen, $best[3]);
                return [$best[0], $best[1]];
            }
            $left = $deadline - microtime(true);
            if ($this->ended || $left <= 0) {
                return null;
            }
            $this->news ??= new DeferredFuture();
            try {
                $this->news->getFuture()->await(new TimeoutCancellation($left));
            } catch (CancelledException) {
                return null;
            }
        }
    }

    /**
     * Sends one line as a person would enter it. A terminal takes the Enter key, a pipe takes a line feed.
     *
     * @return bool whether `agy` was there to take it
     */
    public function send(#[\SensitiveParameter] string $line): bool {
        try {
            $this->process->getStdin()->write($line . ($this->terminal ? "\r" : "\n"));
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function running(): bool {
        return !$this->ended && $this->process->isRunning();
    }

    /** Seconds since the run began. */
    public function elapsed(): float {
        return microtime(true) - $this->startedAt;
    }

    public function stop(): void {
        Children::stop($this->process);
        $this->screen = '';
    }

    /**
     * The text of what a terminal was sent, and the start of a control sequence cut by the end of the chunk.
     *
     * @return array{string, string}
     * @internal
     */
    public static function clean(string $raw): array {
        $carry = '';
        $at = strrpos($raw, "\x1b");
        if ($at !== false && strlen($raw) - $at < 64 && preg_match('#^' . self::SEQUENCE . '#', substr($raw, $at)) !== 1) {
            $carry = substr($raw, $at);
            $raw = substr($raw, 0, $at);
        }
        $text = preg_replace('#' . self::SEQUENCE . '#', '', $raw) ?? '';
        return [preg_replace('#[\x00-\x08\x0b-\x1f\x7f]#', '', $text) ?? '', $carry];
    }

    private function pump(): void {
        $process = $this->process;
        async(function () use ($process): void {
            $carry = '';
            try {
                while (($chunk = $process->getStdout()->read()) !== null) {
                    [$text, $carry] = self::clean($carry . $chunk);
                    $this->screen = substr($this->screen . $text, -self::KEPT);
                    $this->wake();
                }
            } catch (Throwable) {
                // the stream went with the child
            }
            $this->ended = true;
            $this->wake();
        })->ignore();
        async(static function () use ($process): void {
            try {
                while ($process->getStderr()->read() !== null) {
                }
            } catch (Throwable) {
                // the stream went with the child
            }
        })->ignore();
    }

    private function wake(): void {
        $news = $this->news;
        $this->news = null;
        $news?->complete();
    }
}
