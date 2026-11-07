<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

/**
 * One step of a provisioning run (plan Ruling 2, section 3).
 *
 * `run()` either returns the output to journal as `done` (AC-3), or throws -
 * the runner, not the step, decides what the stored `error` says
 * ({@see ProvisioningRunner}, AC-18). A step that wants to record something
 * before its effect runs - an "about to create" marker, a pre-generated id -
 * writes it through `$journal->write()` itself, mid-method, because that
 * write must be committed **before** the effect (Ruling 2's "intent first").
 *
 * `compensate()` is `abandon`'s half (Ruling 10, step 16): it undoes a `done`
 * step's effect and returns a short reason the run's own `error`/output can
 * carry, or null when there is nothing to undo. It is never called by
 * {@see ProvisioningRunner::run()}.
 */
interface ProvisioningStep
{
    /**
     * @return array<string, mixed> the output to journal on success
     */
    public function run(RunContext $context, StepJournal $journal): array;

    /**
     * @return string|null a short note on what was undone, or null for
     *                     nothing to undo
     */
    public function compensate(RunContext $context): ?string;
}
