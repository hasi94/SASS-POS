<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RunTenantMigrations;

use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantResult;

/**
 * What one invocation of the runner did.
 *
 * `exitCode` is a field rather than something the command computes, because
 * AC-44 ties the exit code to the run's outcome - "the command exits
 * non-zero", and a control run in which every tenant succeeds exits 0 - and a
 * command that decided that for itself would be a second rule about what a
 * failed run is.
 */
final readonly class RunTenantMigrationsResult
{
    /**
     * @param  list<MigrateTenantResult>  $jobs  in the order they were executed
     * @param  array<string, int>  $counts  job status => how many, after the run
     */
    public function __construct(
        public string $runId,
        public int $targetVersion,
        public string $status,
        public array $jobs,
        public array $counts,
        public int $exitCode,
    ) {}

    /**
     * The tenants this invocation executed, in order.
     *
     * @return list<string>
     */
    public function tenantIds(): array
    {
        return array_map(static fn (MigrateTenantResult $job): string => $job->tenantId, $this->jobs);
    }
}
