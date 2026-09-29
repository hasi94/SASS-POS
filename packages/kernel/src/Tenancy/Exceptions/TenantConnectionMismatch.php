<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * An invariant between the resolved tenant, the tenant connection
 * configuration and the connection object actually serving a query broke
 * (the belt behind AC-16/AC-17).
 *
 * `staleConnection()` is the load-bearing one: it is what a Connection object
 * held across a tenant switch answers with, instead of quietly serving the
 * previous tenant's data.
 */
final class TenantConnectionMismatch extends TenancyException
{
    /**
     * The configuration handed to the connection factory does not belong to the
     * tenant the context reports. Something other than Tenancy wrote it.
     */
    public static function forConfiguration(string $tenantId, ?string $configuredTenantId): self
    {
        return new self(sprintf(
            'The [tenant] connection configuration belongs to tenant [%s] but tenant [%s] is resolved. '.
            'Flexipos\Kernel\Tenancy\Tenancy is the only code allowed to write that configuration.',
            $configuredTenantId ?? 'unknown',
            $tenantId
        ));
    }

    /**
     * A Connection object built for one tenant was asked to run a query while a
     * different tenant is resolved - a cached or long-held connection surviving
     * a tenant switch.
     */
    public static function forStaleConnection(string $connectionTenantId, string $resolvedTenantId): self
    {
        return new self(sprintf(
            'This database connection was built for tenant [%s] but tenant [%s] is now resolved, so the query '.
            'was refused. A tenant connection must never be cached across a tenant switch; resolve it from the '.
            'connection manager inside the tenant scope instead.',
            $connectionTenantId,
            $resolvedTenantId
        ));
    }

    /**
     * A mapping record asked for a driver the platform does not allow.
     *
     * @param  list<string>  $allowed
     */
    public static function forDisallowedDriver(string $tenantId, string $driver, array $allowed): self
    {
        return new self(sprintf(
            'Tenant [%s] maps to database driver [%s], which is not one of the allowed tenant drivers [%s].',
            $tenantId,
            $driver,
            implode(', ', $allowed)
        ));
    }
}
