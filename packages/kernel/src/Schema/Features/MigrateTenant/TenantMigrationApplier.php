<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\MigrateTenant;

use Flexipos\Kernel\Schema\Exceptions\TenantMigrationFailed;
use Flexipos\Kernel\Schema\Support\MigrationSet;

/**
 * Applying migrations to the tenant connection, as a seam the suite can
 * replace.
 *
 * The seam exists for one reason and it is the plan's (section 11.5, step 11):
 * everything about this slice that does **not** need a real engine - the status
 * transitions, the mirror write, the cleared error, the cache eviction and the
 * error sanitising - has to be provable while `ENGINE_HOLD` is set, and a
 * handler that reached the framework's migrator directly would drag a real
 * schema change into every one of those cases. With the seam, the sqlite half
 * uses a fake that records what it was asked for and can be told to throw, and
 * the engine half uses {@see ArtisanTenantMigrationApplier} against the three
 * reserved databases `ThreeTenantFixture` created by name.
 *
 * Both methods take the connection **name**, never a configuration array: the
 * connection they name was written by `Tenancy::enter()` through
 * `TenantConnectionAuthority`, which is the single connection-construction path
 * AC-60 is about, and an applier that built its own would be a second one.
 */
interface TenantMigrationApplier
{
    /**
     * Apply every migration of the set up to and including `$target` that the
     * tenant does not already carry, in set order.
     *
     * A failure is reported as
     * {@see TenantMigrationFailed}
     * **naming the migration that failed**, with the cause as `previous`.
     * That is part of the contract and not an implementation detail: the job
     * row has an `error_migration` column (AC-44) and the handler has no other
     * way to fill it, so an applier that let a raw driver exception out would
     * leave every failure anonymous.
     *
     * @return list<string> the migrations this call applied, in the order it
     *                      applied them; empty when the tenant was already
     *                      there
     *
     * @throws TenantMigrationFailed
     */
    public function apply(string $connection, MigrationSet $set, int $target): array;

    /**
     * Every migration the tenant's own `migrations` table lists.
     *
     * This is the authority on where a tenant is (D-14); the mapping's
     * `schema_version` is the mirror of it. Read *after* the apply, inside the
     * same tenant scope, so the mirror the runner writes is a reading of the
     * tenant rather than an assumption about what the apply must have done.
     *
     * @return list<string>
     */
    public function appliedMigrations(string $connection): array;
}
