<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api;

use LogicException;

/**
 * A code with no catalogue entry was resolved outside production (T-021
 * AC-12). In production the catalogue degrades to `kernel.server_error`
 * instead of throwing this.
 */
final class UnknownErrorCode extends LogicException {}
