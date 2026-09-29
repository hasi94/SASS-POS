<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

use Flexipos\Kernel\Tenancy\TenantDatabase;

/**
 * The mapping record exists but its status forbids serving the tenant (AC-10).
 *
 * Deny by default (decision D-26): only `active` connects. The finer-grained
 * read-only handling docs/database.md section 4.4 describes for `read_only` and
 * `archived` belongs to FP-210.
 */
final class TenantDatabaseNotActive extends TenancyException
{
    public static function forTenant(string $tenantId, string $status): self
    {
        return new self(sprintf(
            'Tenant [%s] cannot be served: its database mapping status is [%s], not [%s].',
            $tenantId,
            $status,
            TenantDatabase::STATUS_ACTIVE
        ));
    }
}
