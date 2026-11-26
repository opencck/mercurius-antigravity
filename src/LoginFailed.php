<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use RuntimeException;

/** A sign-in that could not be started or continued. */
final class LoginFailed extends RuntimeException {}
