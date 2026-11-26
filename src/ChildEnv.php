<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

/**
 * The environment a child `agy` gets: a short list of what it needs, nothing else of the service's own. The only way
 * in is the sign-in of the plan: a key of the Gemini API or cloud credentials would make `agy` bill API usage in
 * place of the plan, so an inherited one stops the service instead of being dropped silently.
 *
 * spec: services/antigravity/BRIEF.md#среда-agy
 * ref: references/hermes-antigravity-subscription/process.py@6e46b46:L955-L965 — the names `agy` works with in the
 *      plugin's strict mode; the plugin copies the parent's environment and strikes out names that look like secrets
 *      by default, here the environment is always built from a list
 * ref: references/hermes-antigravity-subscription/process.py@6e46b46:L1001-L1026 — the proxy for `agy` and the
 *      loopback kept direct: `agy` talks to its own language server there
 */
final class ChildEnv {
    /** what a process needs to start and to find its files; Windows names are for the tests of developers */
    private const PASSED = ['PATH', 'LANG', 'LC_ALL', 'TZ', 'TMPDIR', 'TEMP', 'TMP', 'SSL_CERT_FILE', 'SSL_CERT_DIR', 'SystemRoot', 'SYSTEMROOT', 'PATHEXT', 'COMSPEC'];
    /** another way to sign in than the plan: refused, not dropped */
    private const REFUSED = ['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GOOGLE_APPLICATION_CREDENTIALS', 'GOOGLE_CLOUD_PROJECT', 'GOOGLE_GENAI_USE_VERTEXAI'];
    /** the pinned version stays: an update is a change of the image */
    private const FIXED = ['AGY_CLI_DISABLE_AUTO_UPDATE' => 'true'];

    /**
     * @param array<string, string> $parent the environment of the service
     * @param non-empty-string $home where `agy` keeps its settings, its log and its sign-in
     * @param ?string $proxy the service's own way out for everything `agy` sends; null — `agy` goes directly
     */
    public function __construct(
        private readonly array $parent,
        private readonly string $home,
        private readonly ?string $proxy = null,
    ) {}

    /** @return non-empty-string */
    public function home(): string {
        return $this->home;
    }

    /**
     * @return array<string, string>
     * @throws RefusedEnvironment
     */
    public function build(): array {
        foreach (self::REFUSED as $name) {
            if (trim($this->parent[$name] ?? '') !== '') {
                throw new RefusedEnvironment(sprintf('%s is set in the environment of the service: agy would sign in with it and bill API usage in place of the plan. Remove it.', $name));
            }
        }
        $env = [];
        foreach (self::PASSED as $name) {
            if (isset($this->parent[$name]) && $this->parent[$name] !== '') {
                $env[$name] = $this->parent[$name];
            }
        }
        $env['HOME'] = $this->home;
        if (PHP_OS_FAMILY === 'Windows') {
            // agy is written in Go: on Windows the home is read from here
            $env['USERPROFILE'] = $this->home;
        }
        if ($this->proxy !== null) {
            foreach (['HTTPS_PROXY' => $this->proxy, 'HTTP_PROXY' => $this->proxy, 'NO_PROXY' => '127.0.0.1,localhost,::1'] as $name => $value) {
                $env[$name] = $value;
                if (PHP_OS_FAMILY !== 'Windows') {
                    $env[strtolower($name)] = $value;
                }
            }
        }
        return [...$env, ...self::FIXED];
    }
}
