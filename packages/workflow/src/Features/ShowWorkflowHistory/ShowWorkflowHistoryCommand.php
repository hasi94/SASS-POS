<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\ShowWorkflowHistory;

use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * `php artisan workflow:history {instance} {--tenant=}` (T-020 Ruling 12).
 */
final class ShowWorkflowHistoryCommand extends Command
{
    protected $signature = 'workflow:history {instance : The instance id} {--tenant= : The tenant id}';

    protected $description = "Show one workflow instance's full trail";

    public function handle(ShowWorkflowHistoryHandler $handler, Tenancy $tenancy): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId === null) {
            $this->components->error('--tenant is required.');

            return self::FAILURE;
        }

        try {
            $result = $tenancy->run((string) $tenantId, fn () => $handler->handle(new ShowWorkflowHistory(
                instanceId: (string) $this->argument('instance'),
            )));
        } catch (WorkflowActionRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        }

        $state = $result->state;

        $header = "status={$state->status->value} current_step={$state->currentStep} definition={$state->definitionKey} on_reject={$state->onReject->value}";

        if ($state->unassignedStep !== null) {
            $header .= " unassignable_step={$state->unassignedStep} (no approver resolved)";
        }

        $this->components->info($header);

        foreach ($result->events as $event) {
            $this->components->info(
                (string) $event->id.' '.(string) $event->event.': '.(string) ($event->actor_employee_id ?? '-')
            );
        }

        return self::SUCCESS;
    }
}
