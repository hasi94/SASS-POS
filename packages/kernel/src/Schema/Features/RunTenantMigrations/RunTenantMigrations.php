<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RunTenantMigrations;

use Flexipos\Kernel\Schema\Support\MigrationSet;

/**
 * One invocation of the runner: plan (or resume) a run and execute its jobs.
 *
 * This is the slice `tenant:migrate` is the entry point of. It owns the two
 * facts no single job can know - when the last job finished, and therefore
 * what the run's final status is (AC-44).
 *
 * `runId` and `retryFailed` are what make an invocation a *resumption* rather
 * than a new run: naming a run plans nothing, so a retry cannot quietly
 * re-select tenants whose state has moved since the plan was written.
 */
final readonly class RunTenantMigrations
{
    /**
     * @param  list<string>|null  $tenantIds  null for a bulk run
     * @param  int|null  $targetVersion  null for the highest version the set reaches
     */
    public function __construct(
        public MigrationSet $set,
        public ?array $tenantIds = null,
        public ?int $targetVersion = null,
        public ?string $runId = null,
        public bool $retryFailed = false,
        public string $workerId = 'cli',
        public int $concurrency = 1,
        public ?string $initiatedBy = null,
    ) {}

    /**
     * Whether this invocation continues an existing run.
     */
    public function resumes(): bool
    {
        return $this->runId !== null;
    }
}
