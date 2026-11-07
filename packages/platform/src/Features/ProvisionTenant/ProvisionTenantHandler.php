<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ProvisionTenant;

use Flexipos\Contracts\Organization\DefaultBranch;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Features\AbandonProvisioning\AbandonProvisioning;
use Flexipos\Platform\Features\AbandonProvisioning\AbandonProvisioningHandler;
use Flexipos\Platform\Provisioning\OwnerCredentialAnnouncement;
use Flexipos\Platform\Provisioning\OwnerPasswordReissue;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRunRefused;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Support\MasterConnection;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * `tenant:provision`'s handler (T-026 Ruling 2, section 5 item 4).
 *
 * **Step 8's own scope.** This class validates (AC-1), enforces
 * `--resume`/`--abandon` mutual exclusion (AC-6, D-28) and plans a fresh run
 * - the three things step 8 proves. {@see self::resolveSteps()} is written
 * generically against {@see StepName::order()} so that no later step (9 to
 * 14) has to touch this file again as `Flexipos\Platform\Provisioning\Steps\*`
 * fills in: each class name is derived from the step's own value
 * (`reserve_identity` -> `ReserveIdentity`), never restated here. Nothing
 * exercises that resolution yet - every real step class it names is built in
 * a later step - so it stays correct, but untested, until step 15's
 * end-to-end proof reaches it.
 *
 * **Abandon is delegated whole.** `--abandon` is recognised here only far
 * enough to take part in AC-1's mutual-exclusion rule; its actual
 * compensation (Ruling 10, AC-9) is `Features\AbandonProvisioning`, step
 * 16's own slice - this method hands the run id straight to it and wraps
 * its result back into a {@see ProvisionTenantResult} so the one command
 * has one result type to print.
 */
final class ProvisionTenantHandler
{
    private const STEP_NAMESPACE = 'Flexipos\\Platform\\Provisioning\\Steps\\';

    public function __construct(
        private readonly MasterConnection $master,
        private readonly DefaultBranch $defaultBranch,
        private readonly ProvisioningRunner $runner,
        private readonly Container $container,
        private readonly AbandonProvisioningHandler $abandon,
        private readonly OwnerPasswordReissue $reissue,
    ) {}

    public function handle(ProvisionTenant $command): ProvisionTenantResult
    {
        $exclusive = array_filter([
            $command->resumeRunId,
            $command->abandonRunId,
            $command->reissueOwnerPasswordRunId,
        ], static fn (?string $value): bool => $value !== null);

        if (count($exclusive) > 1) {
            throw PlatformRefused::validation(
                'The --resume, --abandon and --reissue-owner-password options may not be combined.'
            );
        }

        if ($command->resumeRunId !== null) {
            return $this->resume($command->resumeRunId, $command->actorUserId);
        }

        if ($command->abandonRunId !== null) {
            $result = $this->abandon->handle(new AbandonProvisioning($command->abandonRunId));

            return new ProvisionTenantResult(
                runId: $result->runId,
                status: $result->status,
                abandonMessage: $result->message,
            );
        }

        if ($command->reissueOwnerPasswordRunId !== null) {
            return $this->reissueOwnerPassword($command->reissueOwnerPasswordRunId);
        }

        $input = ProvisionTenantInput::validate($command, $this->master, $this->defaultBranch);

        $runId = $this->runner->plan(
            'create',
            $input->toArray(),
            $command->actorUserId,
            $this->workerId(),
            StepName::order(),
        );

        $this->execute($runId, $input->toArray(), $command->actorUserId);

        return $this->result($runId);
    }

    private function resume(string $runId, ?string $actorUserId): ProvisionTenantResult
    {
        $run = $this->runner->claim($runId, $this->workerId());

        /** @var array<string, mixed> $input */
        $input = $run['input'] === null ? [] : (array) json_decode((string) $run['input'], true);

        $this->execute($runId, $input, $actorUserId);

        return $this->reissueIfAnnouncementIsEmpty($this->result($runId));
    }

    /**
     * Review round 2 R-3 (the residual half of R-1): `create_owner` is
     * skipped on resume once it is already `done`, so a process that
     * crashed *after* that step committed - during `verify`/`activate`/
     * `markRunCompleted`, or any non-`Throwable` death (SIGTERM, an SSH
     * disconnect, OOM, a PHP fatal) - never re-announces anything on this
     * attempt. {@see self::result()}'s announcement is correctly `null` in
     * that case; this reaches for `create_owner`'s own journalled output
     * instead, and if it shows a password-bearing mode nobody has ever
     * reported, reissues through {@see OwnerPasswordReissue} - the same
     * precondition D-31 already defined, never silent either way.
     */
    private function reissueIfAnnouncementIsEmpty(ProvisionTenantResult $result): ProvisionTenantResult
    {
        if ($result->ownerMode !== null) {
            return $result;
        }

        $createOwnerOutput = $this->runner->stepOutput($result->runId, StepName::CreateOwner);
        $mode = $createOwnerOutput['mode'] ?? null;

        if (! in_array($mode, ['created', 'rotated'], true)) {
            return $result;
        }

        return $this->reissueResultFor($result->runId, $result->status, (string) $createOwnerOutput['user_id']);
    }

    /**
     * Review round 2 R-3: works against a `completed` run too - the
     * precondition (never signed in), not the run's own status, is what
     * gates it, so this never goes through {@see ProvisioningRunner::claim()}
     * at all.
     */
    private function reissueOwnerPassword(string $runId): ProvisionTenantResult
    {
        $run = $this->runner->find($runId);

        if ($run === null) {
            throw ProvisioningRunRefused::notFound($runId);
        }

        $createOwnerOutput = $this->runner->stepOutput($runId, StepName::CreateOwner);
        $mode = $createOwnerOutput['mode'] ?? null;

        if (! in_array($mode, ['created', 'rotated'], true)) {
            return new ProvisionTenantResult($runId, (string) $run['status'], 'nothing_to_reissue');
        }

        return $this->reissueResultFor($runId, (string) $run['status'], (string) $createOwnerOutput['user_id']);
    }

    /**
     * The one place both callers above turn an eligible/ineligible
     * {@see OwnerPasswordReissue} outcome into a result - so the two paths
     * cannot drift into two different sentences for the same outcome.
     */
    private function reissueResultFor(string $runId, string $status, string $userId): ProvisionTenantResult
    {
        $reissued = $this->reissue->reissue($userId);

        if ($reissued['eligible']) {
            return new ProvisionTenantResult($runId, $status, 'rotated', (string) $reissued['password']);
        }

        return new ProvisionTenantResult($runId, $status, 'already_signed_in');
    }

    /**
     * `create_owner`'s own in-memory announcement (D-26), read back from the
     * same container-singleton instance the step just wrote to - never
     * persisted anywhere in between.
     */
    private function result(string $runId): ProvisionTenantResult
    {
        $announcement = $this->container->make(OwnerCredentialAnnouncement::class);

        return new ProvisionTenantResult($runId, 'completed', $announcement->mode(), $announcement->password());
    }

    /**
     * Review round 1 R-1: a failure in `verify`/`activate`, or in
     * `create_owner`'s own `linkUser()` half, must still surface whatever
     * {@see OwnerCredentialAnnouncement} already holds - a `created` or
     * `rotated` password exists nowhere else, and AC-18 forbids journalling
     * it, so this is the one chance this process gets to hand it back
     * before the error. A failure with nothing announced (every earlier
     * step, or a resumed run that never reaches `create_owner` again)
     * re-throws the original unchanged.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ProvisionTenantFailedWithOwnerAnnouncement|Throwable
     */
    private function execute(string $runId, array $input, ?string $actorUserId): void
    {
        try {
            $this->runner->run($runId, $this->resolveSteps(), $input, $actorUserId, $this->workerId());
        } catch (Throwable $e) {
            $announcement = $this->container->make(OwnerCredentialAnnouncement::class);

            if ($announcement->mode() === null) {
                throw $e;
            }

            throw new ProvisionTenantFailedWithOwnerAnnouncement($announcement->mode(), $announcement->password(), $e);
        }
    }

    /**
     * One concrete step class per {@see StepName}, resolved by a mechanical
     * name derived from the enum value - see this class's own docblock for
     * why nothing here restates `StepName::order()`'s own sequence.
     *
     * @return array<string, ProvisioningStep>
     */
    private function resolveSteps(): array
    {
        $steps = [];

        foreach (StepName::order() as $stepName) {
            $class = self::STEP_NAMESPACE.Str::studly($stepName->value);
            $resolved = $this->container->make($class);

            if (! $resolved instanceof ProvisioningStep) {
                throw new LogicException("[{$class}] does not implement ProvisioningStep.");
            }

            $steps[$stepName->value] = $resolved;
        }

        return $steps;
    }

    private function workerId(): string
    {
        return 'cli-'.(gethostname() ?: 'unknown').'-'.getmypid();
    }
}
