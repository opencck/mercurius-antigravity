<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use Revolt\EventLoop;
use Throwable;

/**
 * The sign-in of the owner to the plan, done by `agy` itself. Asked a question in print mode without a sign-in, `agy`
 * prints a link of Google and waits for the code the owner gets after signing in in a browser of their own. The
 * service passes the link and the code along and keeps neither. One sign-in at a time; `agy` waits for the code for
 * a minute and then gives up.
 *
 * The steps: `agy` is started — the link is awaited — the code is sent — the end of `agy` is awaited — the sign-in
 * file tells whether it worked. `agy` offers the link only when its input is a terminal (seen on 1.5.18), so this
 * run — never a model call — is a session under a terminal. A question `agy` asks on the way is the owner's to
 * answer: the service answers none and hands its words to the owner.
 *
 * spec: services/antigravity/BRIEF.md#вход
 */
final class Login {
    /** seconds `agy` waits for the code before it gives up */
    public const WAIT = 60.0;
    private const LINK_SECONDS = 20.0;
    private const DONE_SECONDS = 30.0;
    private const STEPS = [
        'link' => '~(https://accounts\.google\.com/\S+)[^\n]*\n~',
        'question' => '~[^\n]*(?:\(y/n\)|\[y/n\]|\(yes/no\)|\[yes/no\])[^\n]*~i',
    ];

    private ?Session $session = null;
    private float $codeFrom = 0.0;
    public readonly Timings $timings;

    public function __construct(private readonly AgyCli $cli, private readonly string $workDir, ?Timings $timings = null) {
        $this->timings = $timings ?? new Timings();
    }

    public function pending(): bool {
        return $this->session !== null && $this->session->running() && $this->session->elapsed() < self::WAIT;
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
            $session = Session::start([...$this->cli->command(), '-p', '/usage', '--output-format', 'json'], $this->workDir, $this->cli->env()->build(), true);
        } catch (Throwable $e) {
            throw new LoginFailed('The Antigravity CLI did not start: ' . $e::class);
        }
        $this->session = $session;
        // a sign-in nobody finished does not stay: agy itself gives up at the same time
        EventLoop::unreference(EventLoop::delay(self::WAIT + 5.0, function () use ($session): void {
            $this->drop($session);
        }));
        $step = $session->expect(self::STEPS, self::LINK_SECONDS);
        if ($step !== null && $step[0] === 'link') {
            $this->timings->note('login_link', $session->elapsed());
            $this->codeFrom = microtime(true);
            return $step[1][1] ?? '';
        }
        $this->drop($session);
        if ($step !== null) {
            throw self::asked($step[1][0] ?? '', '');
        }
        throw new LoginFailed($this->cli->signedIn() ? 'The plan is signed in already.' : 'The Antigravity CLI gave no link to sign in.');
    }

    /**
     * Gives the code to the waiting `agy`.
     *
     * @return bool whether `agy` is signed in after it
     * @throws LoginFailed when no sign-in waits for a code
     */
    public function code(#[\SensitiveParameter] string $code): bool {
        $session = $this->session;
        if ($session === null || !$this->pending()) {
            $this->cancel();
            throw new LoginFailed('No sign-in waits for a code: start it again.');
        }
        if (preg_match('~^[\x21-\x7e]{4,512}$~', $code) !== 1) {
            throw new LoginFailed('The code is not a code of a sign-in.');
        }
        $sent = microtime(true);
        $session->send($code);
        // agy ends by itself once it took the code or refused it; a question on the way stops the wait
        $step = $session->expect(['question' => self::STEPS['question']], self::DONE_SECONDS);
        $this->drop($session);
        if ($step !== null) {
            throw self::asked($step[1][0] ?? '', $code);
        }
        $this->timings->note('login_code_wait', $sent - $this->codeFrom);
        $this->timings->note('login_done', microtime(true) - $sent);
        return $this->cli->signedIn();
    }

    public function cancel(): void {
        if ($this->session !== null) {
            $this->drop($this->session);
        }
    }

    /** Stops one sign-in; another one started meanwhile is left alone. */
    private function drop(Session $session): void {
        if ($this->session === $session) {
            $this->session = null;
        }
        $session->stop();
    }

    /** A question of `agy` goes to the owner in its own words; the code the owner typed is not in them. */
    private static function asked(string $question, #[\SensitiveParameter] string $code): LoginFailed {
        $words = trim($code === '' ? $question : str_replace($code, '', $question));
        return new LoginFailed('The Antigravity CLI asks a question only the owner answers, and the service answers none: "' . substr($words, 0, 300) . '". Open Antigravity with this account, answer there, and start the sign-in again.');
    }
}
