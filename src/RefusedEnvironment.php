<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use RuntimeException;

/** The environment of the service holds something a child must never get: the service does not start. */
final class RefusedEnvironment extends RuntimeException {}
