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
     * The configuration handed to the connection factory carries no
     * authenticator the authority vouches for, while a tenant is resolved.
     * Something other than Tenancy wrote it.
     *
     * Nothing the forger supplied is echoed back, and the resolved tenant is
     * the only identifier named: there is no trustworthy configured tenant to
     * report, because the value that used to be reported here was precisely the
     * one a forger could compute (FP-161, AC-13). No credential and no
     * authenticator reaches this message (AC-16, AC-30).
     */
    public static function forUnauthorisedConfiguration(string $tenantId): self
    {
        return new self(sprintf(
            'The [tenant] connection configuration was not authorised for tenant [%s], so the connection was '.
            'refused. Flexipos\Kernel\Tenancy\Tenancy is the only code allowed to write that configuration.',
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
