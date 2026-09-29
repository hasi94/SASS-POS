<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * The mapping source has no record for this tenant identifier (AC-9).
 *
 * Nothing has been written to the tenant connection configuration when this is
 * raised: the lookup happens before any state changes.
 */
final class TenantDatabaseNotMapped extends TenancyException
{
    public static function forTenant(string $tenantId): self
    {
        return new self(sprintf(
            'No tenant database mapping exists for tenant [%s], so no connection can be built for it.',
            $tenantId
        ));
    }
}
