<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * Entering a *different* tenant while one is already resolved.
 *
 * Re-entering the same tenant is legal and simply nests (a depth counter, no
 * reconfiguration). Switching without leaving first is not: the caller would
 * silently hand the outer scope a different database when the inner scope ends.
 */
final class TenantAlreadyResolved extends TenancyException
{
    public static function forTenant(string $current, string $requested): self
    {
        return new self(sprintf(
            'Tenant [%s] is already resolved for this process; entering tenant [%s] without leaving it first '.
            'is refused. Use nested Tenancy::run() calls for the same tenant, or leave the current one.',
            $current,
            $requested
        ));
    }
}
