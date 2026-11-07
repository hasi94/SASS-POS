<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;

/**
 * The master-row half of a provisioning run: `tenant_provisioning_runs` and
 * `tenant_provisioning_steps`, written and read exactly as D-20/Ruling 2
 * describe.
 *
 * Every write here is one statement, so {@see ProvisioningRunner} can reason
 * about affected-row counts for its compare-and-set lease (Ruling 2's
 * claiming half) without this class ever deciding what a zero means - that
 * is the runner's call, not this repository's.
 */
final class ProvisioningRuns
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Writes the plan whole: one run row, already `running` and claimed by
     * the planning process, and one `pending` step row per name in `$order`
     * - all inside one transaction (Ruling 2's "written whole").
     *
     * @param  array<string, mixed>  $input
     * @param  list<StepName>  $order
     */
    public function plan(
        string $runId,
        string $type,
        array $input,
        ?string $initiatedBy,
        string $claimedBy,
        array $order,
        string $now,
    ): void {
        $this->connection()->transaction(function () use ($runId, $type, $input, $initiatedBy, $claimedBy, $order, $now): void {
            $this->connection()->table('tenant_provisioning_runs')->insert([
                'id' => $runId,
                'tenant_id' => null,
                'type' => $type,
                'source_tenant_id' => null,
                'target_server_id' => null,
                'status' => 'running',
                'current_step' => $order[0]->value,
                'input' => json_encode($input),
                'claimed_by' => $claimedBy,
                'heartbeat_at' => $now,
                'started_at' => $now,
                'finished_at' => null,
                'error' => null,
                'initiated_by' => $initiatedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rows = [];

            foreach ($order as $sequence => $step) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'run_id' => $runId,
                    'step' => $step->value,
                    'sequence' => $sequence,
                    'status' => 'pending',
                    'attempts' => 0,
                    'started_at' => null,
                    'finished_at' => null,
                    'output' => null,
                    'error' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $this->connection()->table('tenant_provisioning_steps')->insert($rows);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array
    {
        $row = $this->runsTable()->where('id', $runId)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The compare-and-set lease (Ruling 2). `$staleThreshold` is a
     * `DATETIME(3)`-formatted instant, computed by the caller so this method
     * stays a single statement with no clock of its own.
     */
    public function claim(string $runId, string $claimedBy, string $now, string $staleThreshold): bool
    {
        $affected = $this->runsTable()
            ->where('id', $runId)
            ->where(function (Builder $query) use ($staleThreshold): void {
                $query->where('status', 'failed')
                    ->orWhere(function (Builder $stale) use ($staleThreshold): void {
                        $stale->where('status', 'running')->where('heartbeat_at', '<', $staleThreshold);
                    });
            })
            ->update([
                'status' => 'running',
                'claimed_by' => $claimedBy,
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

        return $affected > 0;
    }

    /**
     * Review round 1 W-3: the compare-and-set lease is enforced here too,
     * not only at the initial claim - a zero-row update means some other
     * worker has since claimed this run (stale takeover) and this process's
     * own lease is gone, so it must stop rather than keep writing.
     *
     * @throws ProvisioningRunRefused the lease named by `$claimedBy` no
     *                                longer holds this run
     */
    public function touchHeartbeat(string $runId, string $claimedBy, string $now): void
    {
        $this->assertLeaseHeld(
            $this->runsTable()->where('id', $runId)->where('claimed_by', $claimedBy)->update([
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]),
            $runId,
        );
    }

    public function setCurrentStep(string $runId, string $step, string $now): void
    {
        $this->runsTable()->where('id', $runId)->update([
            'current_step' => $step,
            'updated_at' => $now,
        ]);
    }

    /**
     * @throws ProvisioningRunRefused the lease named by `$claimedBy` no
     *                                longer holds this run (review round 1 W-3)
     */
    public function markRunFailed(string $runId, string $claimedBy, string $step, string $error, string $now): void
    {
        $this->assertLeaseHeld(
            $this->runsTable()->where('id', $runId)->where('claimed_by', $claimedBy)->update([
                'status' => 'failed',
                'current_step' => $step,
                'error' => $error,
                'updated_at' => $now,
            ]),
            $runId,
        );
    }

    /**
     * Review round 1 W-3: guarded on both the lease AND `status = 'running'`
     * - the latter so a stale takeover that has since itself abandoned the
     * run (`rolled_back`) can never be overwritten back to `completed` by
     * this, now-superseded, worker finishing its own last step late.
     *
     * @throws ProvisioningRunRefused the lease named by `$claimedBy` no
     *                                longer holds this run, or it is no
     *                                longer `running`
     */
    public function markRunCompleted(string $runId, string $claimedBy, string $now): void
    {
        $this->assertLeaseHeld(
            $this->runsTable()
                ->where('id', $runId)
                ->where('claimed_by', $claimedBy)
                ->where('status', 'running')
                ->update([
                    'status' => 'completed',
                    'current_step' => null,
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]),
            $runId,
        );
    }

    public function markRunRolledBack(string $runId, string $now): void
    {
        $this->runsTable()->where('id', $runId)->update([
            'status' => 'rolled_back',
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function setTenantId(string $runId, string $tenantId, string $now): void
    {
        $this->runsTable()->where('id', $runId)->update([
            'tenant_id' => $tenantId,
            'updated_at' => $now,
        ]);
    }

    public function setTargetServerId(string $runId, string $serverId, string $now): void
    {
        $this->runsTable()->where('id', $runId)->update([
            'target_server_id' => $serverId,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function stepRow(string $runId, string $step): ?array
    {
        $row = $this->stepsTable()->where('run_id', $runId)->where('step', $step)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Every step row for a run, in sequence order - the shape a resumed run
     * walks.
     *
     * @return list<array<string, mixed>>
     */
    public function steps(string $runId): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->stepsTable()->where('run_id', $runId)->orderBy('sequence')->get()->all()
        );
    }

    public function markStepRunning(string $runId, string $step, string $now): void
    {
        $this->stepsTable()->where('run_id', $runId)->where('step', $step)->update([
            'status' => 'running',
            'attempts' => new Expression('attempts + 1'),
            'started_at' => new Expression(
                'coalesce(started_at, '.$this->quote($now).')'
            ),
            'updated_at' => $now,
        ]);
    }

    /**
     * Merges `$output` into whatever this step's journal already holds,
     * committed on its own - never batched with the step's status (Ruling
     * 2's "intent first, then effect").
     *
     * @param  array<string, mixed>  $output
     */
    public function writeStepOutput(string $runId, string $step, array $output): void
    {
        $existing = $this->stepOutput($runId, $step);
        $merged = array_merge($existing, $output);

        $this->stepsTable()->where('run_id', $runId)->where('step', $step)->update([
            'output' => json_encode($merged),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function stepOutput(string $runId, string $step): array
    {
        $row = $this->stepRow($runId, $step);

        if ($row === null || $row['output'] === null) {
            return [];
        }

        return (array) json_decode((string) $row['output'], true);
    }

    /**
     * Every step's journalled output, keyed by step name - what the
     * sanitiser reads to find every credential reference this run has
     * written down so far ({@see ProvisioningErrors}).
     *
     * @return array<string, array<string, mixed>>
     */
    public function allOutputs(string $runId): array
    {
        $outputs = [];

        foreach ($this->steps($runId) as $row) {
            $outputs[(string) $row['step']] = $row['output'] === null
                ? []
                : (array) json_decode((string) $row['output'], true);
        }

        return $outputs;
    }

    /**
     * `tenant_provisioning_steps` has no `claimed_by` column of its own -
     * the lease lives on the run row - so the guard is a correlated
     * `whereExists` against `tenant_provisioning_runs`, portable to both
     * MySQL/MariaDB and the suite's sqlite stand-in (review round 1 W-3).
     *
     * @param  array<string, mixed>  $output
     *
     * @throws ProvisioningRunRefused the lease named by `$claimedBy` no
     *                                longer holds this run
     */
    public function markStepDone(string $runId, string $claimedBy, string $step, array $output, string $now): void
    {
        $merged = array_merge($this->stepOutput($runId, $step), $output);

        $this->assertLeaseHeld(
            $this->leaseGuardedStepsQuery($runId, $claimedBy)->where('step', $step)->update([
                'status' => 'done',
                'output' => json_encode($merged),
                'finished_at' => $now,
                'updated_at' => $now,
            ]),
            $runId,
        );
    }

    /**
     * @throws ProvisioningRunRefused the lease named by `$claimedBy` no
     *                                longer holds this run (review round 1 W-3)
     */
    public function markStepFailed(string $runId, string $claimedBy, string $step, string $error, string $now): void
    {
        $this->assertLeaseHeld(
            $this->leaseGuardedStepsQuery($runId, $claimedBy)->where('step', $step)->update([
                'status' => 'failed',
                'error' => $error,
                'finished_at' => $now,
                'updated_at' => $now,
            ]),
            $runId,
        );
    }

    public function markStepCompensated(string $runId, string $step, ?string $note, string $now): void
    {
        $this->stepsTable()->where('run_id', $runId)->where('step', $step)->update([
            'status' => 'compensated',
            'error' => $note,
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function quote(string $timestamp): string
    {
        return "'".str_replace("'", '', $timestamp)."'";
    }

    /**
     * A steps-table query pre-filtered to rows whose run is still claimed by
     * `$claimedBy` - the cross-table half of the compare-and-set lease
     * (review round 1 W-3).
     */
    private function leaseGuardedStepsQuery(string $runId, string $claimedBy): Builder
    {
        return $this->stepsTable()
            ->where('run_id', $runId)
            ->whereExists(function (Builder $query) use ($runId, $claimedBy): void {
                $query->select('id')
                    ->from('tenant_provisioning_runs')
                    ->where('id', $runId)
                    ->where('claimed_by', $claimedBy);
            });
    }

    /**
     * @throws ProvisioningRunRefused `$affected` is zero: the lease was
     *                                lost to another worker's claim (review
     *                                round 1 W-3)
     */
    private function assertLeaseHeld(int $affected, string $runId): void
    {
        if ($affected === 0) {
            throw ProvisioningRunRefused::leaseLost($runId);
        }
    }

    private function runsTable(): Builder
    {
        return $this->connection()->table('tenant_provisioning_runs');
    }

    private function stepsTable(): Builder
    {
        return $this->connection()->table('tenant_provisioning_steps');
    }

    private function connection(): Connection
    {
        return $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));
    }
}
