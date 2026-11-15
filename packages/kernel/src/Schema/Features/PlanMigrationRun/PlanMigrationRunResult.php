<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\PlanMigrationRun;

/**
 * What one planned run is, as the planner saw it.
 *
 * `jobs` is in the order the rows were written, which is ascending
 * `size_bytes` (AC-40): the order is part of the plan rather than of the
 * reading, so a test asserts it here as well as in the table.
 */
final readonly class PlanMigrationRunResult
{
    /**
     * @param  list<array{job_id: string, tenant_id: string, server_id: string, from_version: int, to_version: int}>  $jobs
     */
    public function __construct(
        public string $runId,
        public int $targetVersion,
        public string $mode,
        public array $jobs,
    ) {}

    /**
     * The tenants planned, in the order their jobs were written.
     *
     * @return list<string>
     */
    public function tenantIds(): array
    {
        return array_map(static fn (array $job): string => $job['tenant_id'], $this->jobs);
    }
}
