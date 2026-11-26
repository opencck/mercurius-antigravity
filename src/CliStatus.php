<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

/**
 * What the CLI is and whether the owner is signed in to the plan.
 *
 * spec: services/antigravity/BRIEF.md#вход
 */
final class CliStatus {
    /** the sign-in `agy` keeps in its home */
    public const LOGIN = 'login';
    public const NONE = 'none';

    public function __construct(
        /** null — the CLI was not found or did not answer */
        public readonly ?string $version,
        public readonly bool $versionOk,
        public readonly string $auth,
        public readonly string $detail = '',
    ) {}

    /** The CLI is there and new enough: the service can work, signed in or not. */
    public function usable(): bool {
        return $this->version !== null && $this->versionOk;
    }

    public function signedIn(): bool {
        return $this->auth === self::LOGIN;
    }
}
