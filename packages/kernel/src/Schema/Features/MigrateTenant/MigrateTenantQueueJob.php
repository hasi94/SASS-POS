<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\MigrateTenant;

use Flexipos\Kernel\Schema\Support\MigrationSet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One tenant's migration, as a queue job (AC-41).
 *
 * **What this class carries is the criterion.** Four identifiers, a version and
 * a list of directory paths - and no username, no credential, no credential
 * *reference* and no password. A queue payload is written to a store, logged by
 * failed-job handlers and read by anyone with queue access, so a secret in one
 * is a secret at rest in a place nobody is watching. The executing worker
 * resolves the mapping itself, through `Tenancy::enter()`, which is the single
 * connection-construction path (AC-60): the payload says *which* tenant, never
 * *how* to connect to it.
 *
 * The set is carried as its name and its directories rather than as a
 * serialised object, so a payload written by one deployment is still readable
 * by the next after the set type changes - the same reason the mapping cache
 * stores an array.
 *
 * `$tries = 1`: retrying is the runner's own business. The job row carries
 * `attempts` and the claim re-checks it against the configured maximum (D-20),
 * and a queue-level retry would increment nothing and could run a second
 * migration against a tenant whose first is still going.
 */
final class MigrateTenantQueueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @see self for why retrying belongs to the runner and not to the queue
     */
    public int $tries = 1;

    /**
     * @param  list<string>  $setDirectories  absolute, in application order
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $jobId,
        public readonly string $tenantId,
        public readonly string $workerId,
        public readonly string $setName,
        public readonly array $setDirectories,
    ) {}

    /**
     * The payload for one claimed job and the set it is being migrated with.
     *
     * The queue name is handed in rather than read here, because
     * `config('schema.queue')` is the host's key and the kernel is told things
     * by the caller that read them - the same rule the tree directory follows.
     */
    public static function forJob(
        string $runId,
        string $jobId,
        string $tenantId,
        string $workerId,
        MigrationSet $set,
        string $queue,
    ): self {
        $job = new self($runId, $jobId, $tenantId, $workerId, $set->name, $set->directories);

        $job->onQueue($queue);

        return $job;
    }

    public function handle(MigrateTenantHandler $handler): void
    {
        $handler->handle(new MigrateTenant(
            $this->jobId,
            $this->workerId,
            MigrationSet::named($this->setName, ...$this->setDirectories)
        ));
    }
}
