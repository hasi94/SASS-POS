<?php

namespace Flexipos\Kernel\Tenancy\Contracts;

use Flexipos\Kernel\Tenancy\TenantMigrationTarget;

/**
 * The runner's mapping source: tenant identifier in, one TenantMigrationTarget
 * or nothing out.
 *
 * A second port rather than a second method on TenantDatabaseRepository,
 * because the two answer different questions and only one of them may ever be
 * reachable from a request. Tenancy consults this one for the Migrating purpose
 * and never for Serving.
 *
 * Read-only and single-record, like its serving sibling: writing the mapping is
 * provisioning's job (FP-206), and null means "no such mapping", which the
 * caller must treat as a refusal and never as "use a default".
 */
interface TenantMigrationTargetRepository
{
    public function find(string $tenantId): ?TenantMigrationTarget;
}
