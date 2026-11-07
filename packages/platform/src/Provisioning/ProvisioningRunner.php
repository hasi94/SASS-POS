<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;
use Throwable;

/**
 * The provisioning run engine (plan Ruling 2, step 7).
 *
 * This class knows nothing about what any of the eleven real steps do - it
 * only knows the generic shape every step shares: run in a fixed order,
 * `done` steps are skipped on resume, a failure stops everything after it,
 * and the stored error is sanitised. The concrete steps (`ReserveIdentity`,
 * `SelectServer`, ...) and the command that drives this are later work; this
 * is deliberately provable with in-test fake steps first.
 */
final class ProvisioningRunner
{
    public function __construct(
        private readonly ProvisioningRuns $runs,
        private readonly RunnerClock $clock,
        private readonly ConfigRepository $config,
        private readonly SecretResolver $secrets,
    ) {}

    /**
     * Writes a fresh run, already claimed by `$claimedBy` (Ruling 2's
     * "written whole" plan). No step runs yet.
     *
     * @param  array<string, mixed>  $input
     * @param  list<StepName>  $order
     */
    public function plan(string $type, array $input, ?string $initiatedBy, string $claimedBy, array $order): string
    {
        $runId = (string) Str::ulid();

        $this->runs->plan($runId, $type, $input, $initiatedBy, $claimedBy, $order, $this->clock->timestamp());

        return $runId;
    }

    /**
     * The compare-and-set lease a `--resume` claims through (Ruling 2,
     * AC-4, AC-6).
     *
     * @return array<string, mixed> the run row, after the claim
     *
     * @throws ProvisioningRunRefused the run does not exist, is already
     *                                `completed`, or is a non-stale
     *                                `running` run
     */
    public function claim(string $runId, string $claimedBy): array
    {
        $run = $this->runs->find($runId);

        if ($run === null) {
            throw ProvisioningRunRefused::notFound($runId);
        }

        if ($run['status'] === 'completed') {
            throw ProvisioningRunRefused::alreadyCompleted($runId);
        }

        if ($run['status'] === 'rolled_back') {
            throw ProvisioningRunRefused::alreadyAbandoned($runId);
        }

        $staleAfterMinutes = (int) $this->config->get('platform.provisioning.stale_after_minutes', 30);
        $now = $this->clock->timestamp();
        $staleThreshold = $this->clock->now()->subMinutes($staleAfterMinutes)->format(RunnerClock::PRECISION);

        if (! $this->runs->claim($runId, $claimedBy, $now, $staleThreshold)) {
            throw ProvisioningRunRefused::leaseHeld($runId, $run['heartbeat_at'] === null ? null : (string) $run['heartbeat_at']);
        }

        return $this->runs->find($runId) ?? $run;
    }

    /**
     * Runs every step of `$steps`, in the order given, skipping any already
     * `done` (AC-3, AC-4). `$input`/`$initiatedBy` build the `RunContext`
     * every step is handed.
     *
     * @param  array<string, ProvisioningStep>  $steps  keyed by
     *                                                  `StepName::value`,
     *                                                  in execution order
     * @param  array<string, mixed>  $input
     * @param  string  $claimedBy  the same worker id this run was planned or
     *                             claimed under - re-checked, not only at
     *                             entry, by every mutating call below
     *                             (review round 1 W-3)
     *
     * @throws ProvisioningStepFailed
     * @throws ProvisioningRunRefused the lease named by `$claimedBy` was
     *                                lost mid-run to another worker's claim
     */
    public function run(string $runId, array $steps, array $input, ?string $initiatedBy, string $claimedBy): void
    {
        $context = new RunContext($runId, $input, $initiatedBy);

        foreach ($steps as $name => $step) {
            $stepName = StepName::from($name);
            $row = $this->runs->stepRow($runId, $name);

            if ($row !== null && $row['status'] === 'done') {
                continue;
            }

            $now = $this->clock->timestamp();
            $this->runs->markStepRunning($runId, $name, $now);
            $this->runs->setCurrentStep($runId, $name, $now);
            $this->runs->touchHeartbeat($runId, $claimedBy, $now);

            $journal = new StepJournal($this->runs, $runId, $stepName);

            try {
                $output = $step->run($context, $journal);
            } catch (Throwable $e) {
                $this->fail($runId, $claimedBy, $stepName, $e);
            }

            $now = $this->clock->timestamp();
            $this->runs->markStepDone($runId, $claimedBy, $name, $output, $now);
            $this->runs->touchHeartbeat($runId, $claimedBy, $now);
        }

        $this->runs->markRunCompleted($runId, $claimedBy, $this->clock->timestamp());
    }

    /**
     * @throws ProvisioningStepFailed always
     */
    private function fail(string $runId, string $claimedBy, StepName $step, Throwable $e): never
    {
        $needles = ProvisioningErrors::needlesFor($this->runs->allOutputs($runId), $this->secrets);
        $sanitised = ProvisioningErrors::sanitise($e->getMessage(), $needles);
        $now = $this->clock->timestamp();

        $this->runs->markStepFailed($runId, $claimedBy, $step->value, $sanitised, $now);
        $this->runs->markRunFailed($runId, $claimedBy, $step->value, $sanitised, $now);

        throw new ProvisioningStepFailed($step, $sanitised);
    }
}
