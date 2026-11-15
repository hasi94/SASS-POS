<?php

declare(strict_types=1);

namespace Flexipos\Organization\Exceptions;

use RuntimeException;

/**
 * An activation code cannot be issued because of the server's own data
 * (T-014 Ruling 7).
 *
 * **Not a refusal and not the caller's mistake**: the tenant's stored code
 * does not fit the activation-code format (it must be upper-case letters,
 * digits and inner hyphens), or the tenant has no live row. The caller sent
 * nothing wrong, so this is a server error - it is logged at error level by
 * the issuer, it escapes as a 500, and it is thrown **before any write**. The
 * message names the tenant id and never a code.
 */
final class ActivationCodeUnavailable extends RuntimeException {}
