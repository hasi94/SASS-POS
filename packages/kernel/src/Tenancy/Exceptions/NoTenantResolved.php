<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * No tenant has been resolved for this process, so tenant-scoped work has no
 * answer to "which shop is this for" and is refused (AC-4, AC-5, AC-18).
 */
final class NoTenantResolved extends TenancyException
{
    /**
     * Raised when something tries to build or query the tenant connection.
     */
    public static function forConnection(string $connection): self
    {
        return new self(sprintf(
            'No tenant has been resolved for this process, so the [%s] database connection cannot be used. '.
            'Tenant-scoped work must run inside Flexipos\Kernel\Tenancy\Tenancy::run().',
            $connection
        ));
    }

    /**
     * Raised when application code asks the context for a tenant it does not
     * have. TenantContext never answers such a question with null.
     */
    public static function forContext(string $asked): self
    {
        return new self(sprintf(
            'No tenant has been resolved for this process, so the current tenant %s is unknown. '.
            'Call TenantContext::hasTenant() first, or run the work inside Tenancy::run().',
            $asked
        ));
    }
}
