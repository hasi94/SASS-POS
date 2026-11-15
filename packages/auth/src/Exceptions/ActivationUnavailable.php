<?php

declare(strict_types=1);

namespace Flexipos\Auth\Exceptions;

use RuntimeException;

/**
 * Activation failed for a reason that is the server's and not the caller's
 * (T-014 Ruling 12): the master could not write the refresh row, or the tenant
 * database could not be committed. A 503, **not the uniform refusal and not
 * counted** - an outage must never lock a tenant out of activating, and a
 * caller must never be told "wrong code" when the code was fine.
 */
final class ActivationUnavailable extends RuntimeException {}
