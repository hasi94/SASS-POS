<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\ClaimMigrationJob;

/**
 * One worker asks one run for one job.
 *
 * The worker identifier is carried rather than derived, because it is what a
 * stuck job is read back by: `claimed_by` is the only thing that says which
 * process stopped.
 */
final readonly class ClaimMigrationJob
{
    public function __construct(
        public string $runId,
        public string $workerId,
    ) {}
}
