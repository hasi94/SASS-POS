<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\MigrateTenant;

use Flexipos\Kernel\Schema\Support\MigrationSet;

/**
 * Execute one claimed job: migrate one tenant to the version its job names.
 *
 * Three fields, and each is here rather than derived for a reason.
 *
 * - `jobId` is the unit of work. The **target version is not carried**: it is
 *   read from the job row the planner wrote, so a worker cannot migrate a
 *   tenant to a version nobody planned.
 * - `workerId` is who is asking, and it is load-bearing rather than
 *   informational. The handler reads the job *as this worker* and the statement
 *   that completes the job re-checks that the worker still holds it - a lease
 *   can expire mid-migration and another worker can take the job over, and a
 *   completion written without that re-check would mark the new holder's job
 *   done.
 * - `set` is the migration set, because a version is a position in a set and
 *   the entry point that built it is the one place that read
 *   `config('schema.tenant_migration_path')`.
 */
final readonly class MigrateTenant
{
    public function __construct(
        public string $jobId,
        public string $workerId,
        public MigrationSet $set,
    ) {}
}
