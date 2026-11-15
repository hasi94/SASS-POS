<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\ClaimMigrationJob;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

/**
 * Claim one job of one run for one worker (AC-47, AC-48, AC-50).
 *
 * **The claim is granted by exactly one statement, and the verdict is that
 * statement's affected-row count.** AC-48 as `ba` reworded it: *the mutation
 * that grants the claim is a single statement whose `WHERE` re-checks status
 * and attempts*. Reading candidates first grants nothing - the read below is
 * only a list of ids worth trying, and every term it filtered on is re-checked
 * inside the update, so a second worker that read the same list loses the race
 * at the update rather than winning it at the read.
 *
 * It is not `SELECT ... FOR UPDATE SKIP LOCKED`: that is a syntax error on
 * MariaDB 10.4.32 (MEASURED, D-25), which is why the design is an update
 * judged by its row count rather than a select-then-write.
 *
 * **What the `WHERE` re-checks, and why each term is there:**
 *
 * - `id = ?` - the one row this attempt is for;
 * - `attempts < :max` - D-20, three attempts. A job that always fails stops
 *   instead of being retried for ever, and the cap is re-checked *here* rather
 *   than in the read, so a worker whose read was stale cannot exceed it;
 * - the status term - `pending`, or `running` whose lease has **expired**.
 *   This is the whole of AC-47: a worker that died holds its job until the
 *   lease runs out, and then exactly one other worker may take it.
 *
 * Remove either of those two terms and the statement still claims jobs - it
 * just claims ones it must not. That is the mutation this slice is proven red
 * by, and it is a legitimate one: the statement is an `UPDATE` on a master row
 * in the suite own SQLite, not a destructive statement and not a safety guard
 * being stubbed permissively (plan section 11.5, step 10).
 *
 * **The per-server cap** (AC-50) is in the candidate read rather than in the
 * claim, because it is a question about *other* rows and an `UPDATE` cannot
 * ask one: MariaDB 10.4 refuses a subquery on the table being updated unless
 * it is wrapped, and the wrapped form is a derived table - which is exactly
 * what `joinSub` builds here. A cap exceeded between the read and the update
 * costs one extra claim, never a lost job; the tenant level guarantee is the
 * per-tenant advisory lock, not this cap.
 *
 * **Order.** Candidates come back in job-id order, and the ids are ULIDs
 * written by the planner in ascending `size_bytes`, so the smallest tenant is
 * tried first without this slice having to read the mapping again.
 */
final class ClaimMigrationJobHandler
{
    /**
     * The status a claim moves a job into.
     */
    public const CLAIMED_STATUS = 'running';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
    ) {}

    /**
     * The job this worker now holds, or null when the run has none for it.
     */
    public function handle(ClaimMigrationJob $command): ?ClaimedJob
    {
        $now = $this->clock->timestamp();
        $maxAttempts = (int) $this->config->get('schema.max_attempts', 3);
        $ttl = (int) $this->config->get('schema.claim_ttl_minutes', 30);
        $expiresAt = $this->clock->timestampInMinutes($ttl);

        foreach ($this->candidateIds($command->runId, $now, $maxAttempts) as $jobId) {
            $claimed = $this->claim($jobId, $command->workerId, $now, $expiresAt, $maxAttempts);

            if ($claimed === 1) {
                return $this->read($jobId);
            }
        }

        return null;
    }

    /**
     * The ids worth trying, in plan order, with the per-server cap applied.
     *
     * This read grants nothing. Every term here is re-checked by the update,
     * so a stale list costs an attempt that affects zero rows.
     *
     * @return list<string>
     */
    private function candidateIds(string $runId, string $now, int $maxAttempts): array
    {
        $perServerLimit = (int) $this->config->get('schema.per_server_limit', 2);

        // Running jobs whose lease has not expired, per server. An expired
        // lease is a worker that died, so it does not occupy a slot.
        $busy = $this->table()
            ->selectRaw('server_id, count(*) as running_count')
            ->where('status', self::CLAIMED_STATUS)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('lock_expires_at')->orWhere('lock_expires_at', '>', $now);
            })
            ->groupBy('server_id');

        $rows = $this->table()
            ->select('tenant_migration_jobs.id')
            ->leftJoinSub($busy, 'busy', 'busy.server_id', '=', 'tenant_migration_jobs.server_id')
            ->where('tenant_migration_jobs.run_id', $runId)
            ->where('tenant_migration_jobs.attempts', '<', $maxAttempts)
            ->where(fn (Builder $query) => $this->claimableStatus($query, $now))
            ->whereRaw('coalesce(busy.running_count, 0) < ?', [$perServerLimit])
            ->orderBy('tenant_migration_jobs.id')
            ->get();

        return array_map(static fn ($row): string => (string) ((array) $row)['id'], $rows->all());
    }

    /**
     * The one statement that grants the claim. Its return value is the
     * verdict.
     */
    private function claim(string $jobId, string $workerId, string $now, string $expiresAt, int $maxAttempts): int
    {
        return $this->table()
            ->where('id', $jobId)
            ->where('attempts', '<', $maxAttempts)
            ->where(fn (Builder $query) => $this->claimableStatus($query, $now))
            ->update([
                'status' => self::CLAIMED_STATUS,
                'claimed_by' => $workerId,
                'claimed_at' => $now,
                'lock_expires_at' => $expiresAt,
                'attempts' => new Expression('attempts + 1'),
                'started_at' => new Expression('coalesce(started_at, '.$this->quote($now).')'),
            ]);
    }

    /**
     * Claimable is `pending`, or `running` with an expired lease (AC-47).
     *
     * One private method, used by the candidate read and by the claim, so the
     * two can never drift into two different ideas of claimable - and so the
     * mutation that removes the status term removes it from the statement that
     * grants, which is the one the criterion is about.
     */
    private function claimableStatus(Builder $query, string $now): Builder
    {
        return $query
            ->where('status', 'pending')
            ->orWhere(function (Builder $expired) use ($now): void {
                $expired->where('status', self::CLAIMED_STATUS)
                    ->whereNotNull('lock_expires_at')
                    ->where('lock_expires_at', '<=', $now);
            });
    }

    /**
     * The claimed row, read back after the claim rather than before it: the
     * values a caller acts on are the ones the update wrote.
     */
    private function read(string $jobId): ?ClaimedJob
    {
        $row = $this->table()->where('id', $jobId)->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;

        return new ClaimedJob(
            jobId: (string) $row['id'],
            runId: (string) $row['run_id'],
            tenantId: (string) $row['tenant_id'],
            serverId: (string) $row['server_id'],
            fromVersion: $row['from_version'] === null ? null : (int) $row['from_version'],
            toVersion: (int) $row['to_version'],
            attempts: (int) $row['attempts'],
            lockExpiresAt: (string) $row['lock_expires_at'],
        );
    }

    /**
     * A timestamp, quoted for the one place it goes into an expression rather
     * than into a binding: `coalesce(started_at, ...)` is an expression, and
     * the value is this clock own formatted timestamp, never user input.
     */
    private function quote(string $timestamp): string
    {
        return "'".str_replace("'", '', $timestamp)."'";
    }

    private function table(): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table('tenant_migration_jobs');
    }
}
