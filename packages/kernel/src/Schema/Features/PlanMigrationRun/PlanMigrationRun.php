<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\PlanMigrationRun;

use Flexipos\Kernel\Schema\Support\MigrationSet;

/**
 * Plan one migration run: a run row and a job row per selected tenant.
 *
 * The command carries the **set** rather than a directory, because a version is
 * a position in a set and the plan is written in versions (see
 * {@see MigrationSet}). The entry point that builds the set is the one place
 * that reads `config('schema.tenant_migration_path')`.
 *
 * `tenantIds` is the whole of the bulk/named distinction, and the distinction
 * is a rule rather than a convenience: a bulk run takes only the statuses D-11
 * calls safe to touch unattended, while a run that names a tenant also accepts
 * `provisioning` and `failed`, because an operator naming a tenant has already
 * made the judgement a bulk run must not make for them.
 */
final readonly class PlanMigrationRun
{
    /**
     * @param  list<string>|null  $tenantIds  null for a bulk run
     * @param  int|null  $targetVersion  null for the highest version the set reaches
     */
    public function __construct(
        public MigrationSet $set,
        public ?array $tenantIds = null,
        public ?int $targetVersion = null,
        public ?string $initiatedBy = null,
        public int $concurrency = 1,
    ) {}

    /**
     * Whether this run names its tenants.
     */
    public function namesTenants(): bool
    {
        return $this->tenantIds !== null;
    }

    /**
     * The value written to `tenant_migration_runs.mode`.
     */
    public function mode(): string
    {
        return $this->namesTenants() ? 'tenant' : 'bulk';
    }
}
