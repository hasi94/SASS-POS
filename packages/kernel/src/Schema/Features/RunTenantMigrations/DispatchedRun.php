<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RunTenantMigrations;

/**
 * What a `--queue` invocation handed to the workers (AC-41).
 *
 * Deliberately not a `RunTenantMigrationsResult`: that type answers "what did
 * this run do", and a dispatched run has done nothing yet. Reporting one here
 * would give an operator a counters array of zeroes that reads like a finished
 * run.
 *
 * The run id is on it because the closing instruction needs it: the run's
 * final counters are written by `tenant:migrate --run=<id>` once the queue has
 * drained, and an operator who was not told the id cannot do that.
 */
final readonly class DispatchedRun
{
    /**
     * @param  list<string>  $jobIds  the job rows handed to the queue, in plan order
     */
    public function __construct(
        public string $runId,
        public int $targetVersion,
        public string $queue,
        public array $jobIds,
    ) {}
}
