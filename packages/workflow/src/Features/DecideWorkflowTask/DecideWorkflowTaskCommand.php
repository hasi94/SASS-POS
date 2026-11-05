<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\DecideWorkflowTask;

use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * `php artisan workflow:decide {task} {--tenant=} {--as=} {--approve}
 * {--reject} {--comment=}` (T-020 AC-25, Ruling 12).
 */
final class DecideWorkflowTaskCommand extends Command
{
    protected $signature = 'workflow:decide {task : The task id}
        {--tenant= : The tenant id}
        {--as= : The deciding employee id}
        {--approve : Approve the task}
        {--reject : Reject the task}
        {--comment= : An optional comment}';

    protected $description = 'Approve or reject one pending workflow task';

    public function handle(DecideWorkflowTaskHandler $handler, Tenancy $tenancy): int
    {
        $tenantId = $this->option('tenant');
        $actorId = $this->option('as');

        if ($tenantId === null || $actorId === null) {
            $this->components->error('Both --tenant and --as are required.');

            return self::FAILURE;
        }

        $approve = (bool) $this->option('approve');
        $reject = (bool) $this->option('reject');

        if ($approve === $reject) {
            $this->components->error('Exactly one of --approve or --reject is required.');

            return self::FAILURE;
        }

        $verdict = $approve ? WorkflowVerdict::Approve : WorkflowVerdict::Reject;

        try {
            $state = $tenancy->run((string) $tenantId, fn () => $handler->handle(new DecideWorkflowTask(
                taskId: (string) $this->argument('task'),
                actorEmployeeId: (string) $actorId,
                verdict: $verdict,
                comment: $this->option('comment') === null ? null : (string) $this->option('comment'),
            )));
        } catch (WorkflowActionRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        } catch (WorkflowEvaluationFailed $failed) {
            $this->components->error("Refused [{$failed->cause}]: {$failed->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Instance {$state->id} is now [{$state->status->value}], step {$state->currentStep}.");

        return self::SUCCESS;
    }
}
