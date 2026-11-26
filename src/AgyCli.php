<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\Future;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Throwable;

use function Amp\async;

/**
 * The official Antigravity CLI (`agy`) as the service runs it: found, of which version, signed in or not, and which
 * models the plan lists. The binary is never changed. Its sign-in file is never read, copied or written: the service
 * only looks whether it is there.
 *
 * spec: services/antigravity/BRIEF.md#вход
 * ref: references/hermes-antigravity-subscription/process.py@6e46b46:L25-L34 — the name of the sign-in file without
 *      a keyring, and L754-L779 — "signed in" is a file longer than ten bytes, its content is not read
 * ref: references/hermes-antigravity-subscription/models.py@6e46b46:L149-L176 — the list of `agy models`: a model a
 *      line, the lines of the spinner and of messages dropped
 */
final class AgyCli {
    private const PROBE_SECONDS = 20.0;
    private const STREAM_MAX = 1024 * 1024;
    private const SIGN_IN_FILES = ['jetski-standalone-oauth-token', 'antigravity-oauth-token'];
    private const NOISE = '~^(fetching|error:|usage:|available models:|warning:|please )~i';

    /**
     * @param non-empty-list<string> $command the CLI and its fixed arguments
     * @param non-empty-string $minVersion the oldest CLI the service works with
     */
    public function __construct(
        private readonly array $command,
        private readonly ChildEnv $env,
        private readonly string $minVersion,
        private readonly float $probeSeconds = self::PROBE_SECONDS,
    ) {}

    /** @return non-empty-list<string> */
    public function command(): array {
        return $this->command;
    }

    public function env(): ChildEnv {
        return $this->env;
    }

    /** The version the CLI names; null when it is not found or does not answer. */
    public function version(): ?string {
        try {
            [$code, $out] = $this->run(['--version']);
        } catch (Throwable) {
            return null;
        }
        return $code === 0 && preg_match('~\d+\.\d+\.\d+~', $out, $m) === 1 ? $m[0] : null;
    }

    public function status(): CliStatus {
        $version = $this->version();
        if ($version === null) {
            return new CliStatus(null, false, CliStatus::NONE, 'The Antigravity CLI is not found in the image of the service.');
        }
        if (!version_compare($version, $this->minVersion, '>=')) {
            return new CliStatus($version, false, CliStatus::NONE, sprintf('The Antigravity CLI %s is older than %s the service needs.', $version, $this->minVersion));
        }
        return $this->signedIn()
            ? new CliStatus($version, true, CliStatus::LOGIN)
            : new CliStatus($version, true, CliStatus::NONE, 'Nobody is signed in to the Antigravity plan.');
    }

    /** Whether `agy` keeps a sign-in in its home: the file is there and is not empty. Its content is not read. */
    public function signedIn(): bool {
        return $this->signInFile() !== null;
    }

    /** @return ?non-empty-string */
    public function signInFile(): ?string {
        foreach (self::SIGN_IN_FILES as $name) {
            $path = $this->dataDir() . '/' . $name;
            clearstatcache(true, $path);
            if (is_file($path) && (int) @filesize($path) > 10) {
                return $path;
            }
        }
        return null;
    }

    /** Where `agy` keeps its sign-in, its log and the records of conversations. */
    public function dataDir(): string {
        return $this->env->home() . '/.gemini/antigravity-cli';
    }

    /**
     * The models the plan lists, as `agy models` names them; null — the CLI did not say.
     *
     * @return ?list<string>
     */
    public function models(): ?array {
        try {
            [$code, $out] = $this->run(['models']);
        } catch (Throwable) {
            return null;
        }
        if ($code !== 0) {
            return null;
        }
        $ids = [];
        foreach (preg_split('~\R~', $out) ?: [] as $line) {
            $line = trim($line);
            $id = strtolower((string) strtok($line, " \t"));
            if ($line !== '' && preg_match(self::NOISE, $line) !== 1 && preg_match(Models::ID, $id) === 1) {
                $ids[$id] = true;
            }
        }
        return $ids === [] ? null : array_keys($ids);
    }

    /**
     * One short run of the CLI with its input closed.
     *
     * @param list<string> $args
     * @return array{int, string, string} the exit code, stdout and stderr
     * @throws RefusedEnvironment
     * @throws CancelledException when it took too long
     */
    public function run(array $args, ?string $cwd = null, ?float $seconds = null): array {
        $process = Process::start([...$this->command, ...$args], $cwd, $this->env->build());
        $process->getStdin()->close();
        $out = self::reader($process->getStdout());
        $err = self::reader($process->getStderr());
        try {
            $code = $process->join(new TimeoutCancellation($seconds ?? $this->probeSeconds));
        } catch (CancelledException $e) {
            $process->kill();
            $out->ignore();
            $err->ignore();
            throw $e;
        }
        return [$code, $out->await(), $err->await()];
    }

    /** @return Future<string> */
    private static function reader(ReadableStream $stream): Future {
        return async(static function () use ($stream): string {
            $kept = '';
            try {
                while (($chunk = $stream->read()) !== null) {
                    if (strlen($kept) < self::STREAM_MAX) {
                        $kept .= substr($chunk, 0, self::STREAM_MAX - strlen($kept));
                    }
                }
            } catch (Throwable) {
                // the stream went with the child
            }
            return $kept;
        });
    }
}
