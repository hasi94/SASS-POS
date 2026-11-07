<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ProvisionTenant;

use Flexipos\Contracts\Organization\DefaultBranch;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\OwnerCredentialAnnouncement;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Support\MasterConnection;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use LogicException;

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
 * **Abandon is not built here.** `--abandon` is recognised only far enough
 * to take part in AC-1's mutual-exclusion rule; its actual compensation
 * (Ruling 10, AC-9) is `Features\AbandonProvisioning`, step 16's own file.
 */
final class ProvisionTenantHandler
{
    private const STEP_NAMESPACE = 'Flexipos\\Platform\\Provisioning\\Steps\\';

    public function __construct(
        private readonly MasterConnection $master,
        private readonly DefaultBranch $defaultBranch,
        private readonly ProvisioningRunner $runner,
        private readonly Container $container,
    ) {}

    public function handle(ProvisionTenant $command): ProvisionTenantResult
    {
        if ($command->resumeRunId !== null && $command->abandonRunId !== null) {
            throw PlatformRefused::validation('The --resume and --abandon options may not be combined.');
        }

        if ($command->resumeRunId !== null) {
            return $this->resume($command->resumeRunId, $command->actorUserId);
        }

        if ($command->abandonRunId !== null) {
            // Step 16's Features\AbandonProvisioning is the real
            // implementation (Ruling 10, AC-9); nothing here compensates a
            // run yet.
            throw new LogicException(
                'tenant:provision --abandon is not implemented yet (T-026 step 16, Features\\AbandonProvisioning).'
            );
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

        return $this->result($runId);
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
     * @param  array<string, mixed>  $input
     */
    private function execute(string $runId, array $input, ?string $actorUserId): void
    {
        $this->runner->run($runId, $this->resolveSteps(), $input, $actorUserId);
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
