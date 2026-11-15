<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RetryFailedJobs;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Put the failed jobs of one run back to `pending` (AC-46).
 *
 * **The read-then-write shape, and the pin it costs.** The candidate read
 * selects the run's jobs that are `failed` with `attempts` below the maximum,
 * and the statement that resets them re-checks both of those terms. In one
 * process the read never offers a row the statement would wrongly reset, so no
 * behavioural case here can tell the two sites apart - which is plan section
 * 7's fourth shape, measured on this runner in step 10. The reset statement's
 * `WHERE` is therefore pinned from the query log in this slice's test, in the
 * same commit as the statement.
 *
 * The re-check is not decoration. Between the read and the write another
 * worker can claim and finish one of these jobs - the retry and a running
 * worker are two processes against one table - and a reset written on the id
 * alone would drag a `done` job back to `pending` and re-apply a migration
 * that had already succeeded. That is AC-46's "the tenants already done have
 * unchanged `migrations` tables" failing by the retry's own hand.
 *
 * **What is cleared and what is kept.** The claim fields go, because the job
 * is nobody's now; the error fields go, because they describe the attempt that
 * failed and leaving them would make a job that later succeeds read as one
 * that failed. `attempts` is **kept**: it is the count that stops a job failing
 * for a structural reason from being retried for ever (D-20), and a retry that
 * reset it would turn the cap into a suggestion.
 */
final class RetryFailedJobsHandler
{
    public const JOB_PENDING = 'pending';

    public const JOB_FAILED = 'failed';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(RetryFailedJobs $command): RetryFailedJobsResult
    {
        $maxAttempts = (int) $this->config->get('schema.max_attempts', 3);

        $reset = [];
        $exhausted = [];

        foreach ($this->failedJobs($command) as $row) {
            if ((int) $row['attempts'] >= $maxAttempts) {
                $exhausted[] = (string) $row['id'];

                continue;
            }

            if ($this->reset((string) $row['id'], $maxAttempts) === 1) {
                $reset[] = (string) $row['id'];
            }
        }

        return new RetryFailedJobsResult($command->runId, $reset, $exhausted);
    }

    /**
     * The jobs worth trying to reset. This read grants nothing: both of its
     * terms are re-checked by the statement below.
     *
     * @return list<array<string, mixed>>
     */
    private function failedJobs(RetryFailedJobs $command): array
    {
        $query = $this->table()
            ->select(['id', 'tenant_id', 'attempts'])
            ->where('run_id', $command->runId)
            ->where('status', self::JOB_FAILED);

        if ($command->tenantIds !== null) {
            $query->whereIn('tenant_id', $command->tenantIds);
        }

        $rows = [];

        foreach ($query->orderBy('id')->get() as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    /**
     * The one statement that makes a job claimable again, and its verdict is
     * the affected-row count.
     */
    private function reset(string $jobId, int $maxAttempts): int
    {
        return $this->table()
            ->where('id', $jobId)
            ->where('status', self::JOB_FAILED)
            ->where('attempts', '<', $maxAttempts)
            ->update([
                'status' => self::JOB_PENDING,
                'claimed_by' => null,
                'claimed_at' => null,
                'lock_expires_at' => null,
                'finished_at' => null,
                'error_class' => null,
                'error_message' => null,
                'error_migration' => null,
            ]);
    }

    private function table(): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table('tenant_migration_jobs');
    }
}
