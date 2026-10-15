<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Exceptions;

use RuntimeException;

/**
 * The per-tenant advisory lock was asked of a connection that cannot grant it.
 *
 * It throws rather than returning false, and that direction is the whole
 * reason the class exists. `GET_LOCK(name, 0)` is a MySQL and MariaDB
 * function; the suite stand-in is SQLite, which has no such thing. A lock
 * helper that quietly answered "granted" on a driver without the function
 * would be a guard stubbed permissively by the driver rather than by a
 * developer - the exact shape plan section 7 forbids - and the first thing it
 * would permit is two runners migrating one tenant at once.
 *
 * So the unsupported driver is an error with the driver named, and the real
 * behaviour is an engine criterion (AC-49, AC-65) that stays NOT VERIFIED
 * while `ENGINE_HOLD` is set and gate G-1 is unmet.
 */
final class TenantLockUnsupported extends RuntimeException
{
    public static function onDriver(string $driver, string $lock): self
    {
        return new self(
            'The per-tenant advisory lock ['.$lock.'] needs GET_LOCK, which the ['.$driver.'] driver'
            .' does not have. The lock is never assumed granted on a driver that cannot grant it:'
            .' two runners inside one tenant is what it exists to prevent.'
        );
    }
}
