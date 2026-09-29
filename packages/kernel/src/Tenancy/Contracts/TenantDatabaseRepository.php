<?php

namespace Flexipos\Kernel\Tenancy\Contracts;

use Flexipos\Kernel\Tenancy\TenantDatabase;

/**
 * The mapping source: tenant identifier in, one TenantDatabase or nothing out.
 *
 * This is the seam FP-203 replaces. T-003 ships only
 * InMemoryTenantDatabaseRepository, so nothing in this task queries a real
 * `tenant_databases` or `database_servers` table (AC-25); FP-203 binds a
 * master-database-backed implementation and, behind it, the 300s caching
 * decorator docs/database.md section 4.4 describes - without Tenancy changing.
 *
 * The contract is deliberately read-only and single-record: writing the mapping
 * is provisioning's job (FP-206), and a batch lookup has no caller yet.
 */
interface TenantDatabaseRepository
{
    /**
     * Null means "no such mapping", which the caller must treat as a refusal -
     * never as "use a default".
     */
    public function find(string $tenantId): ?TenantDatabase;
}
