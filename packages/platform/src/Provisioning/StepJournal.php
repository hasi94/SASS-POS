<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

/**
 * One step's window onto its own run's journal (Ruling 2).
 *
 * `write()` commits immediately, independently of the step's `running`/`done`
 * transition - that is what lets a step journal an identity it chose (a
 * pre-generated id, an "about to create" marker) **before** attempting the
 * effect that identity names, so a crash between the two leaves the journal
 * entry for the resumed attempt to find (AC-5).
 *
 * `read()` is this step's own output so far, for "have I already done this
 * part" checks on resume. `outputOf()` reads a different, already-`done`
 * step's output - the cross-step references later steps need (a reserved
 * tenant id, a selected server id, a created branch id).
 *
 * `$claimedBy` (review round 2 W-3) is the worker id {@see ProvisioningRunner}
 * constructs this journal with for every real step execution, so `write()`
 * is refused the instant this process's lease on the run is gone, not only
 * on the runner's own later, already-checked writes. It is `null` only when
 * a test constructs a journal directly to seed an earlier step's output as
 * a fixture, outside the runner entirely.
 */
final class StepJournal
{
    public function __construct(
        private readonly ProvisioningRuns $runs,
        public readonly string $runId,
        public readonly StepName $step,
        private readonly ?string $claimedBy = null,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     */
    public function write(array $output): void
    {
        $this->runs->writeStepOutput($this->runId, $this->step->value, $output, $this->claimedBy);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return $this->runs->stepOutput($this->runId, $this->step->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function outputOf(StepName $step): array
    {
        return $this->runs->stepOutput($this->runId, $step->value);
    }
}
