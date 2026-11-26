<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use RuntimeException;

/** A request the service cannot turn into a model call: the answer is 400 with these words. */
final class RequestRejected extends RuntimeException {}
