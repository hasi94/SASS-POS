<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\CancelWorkflowInstance;

use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * `php artisan workflow:cancel {instance} {--tenant=} {--as=} {--comment=}`
 * (T-020 AC-21, Ruling 12).
 */
final class CancelWorkflowInstanceCommand extends Command
{
    protected $signature = 'workflow:cancel {instance : The instance id}
        {--tenant= : The tenant id}
        {--as= : The cancelling employee id}
        {--comment= : An optional comment}';

    protected $description = 'Cancel one pending workflow instance';

    public function handle(CancelWorkflowInstanceHandler $handler, Tenancy $tenancy): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId === null) {
            $this->components->error('--tenant is required.');

            return self::FAILURE;
        }

        try {
            $state = $tenancy->run((string) $tenantId, fn () => $handler->handle(new CancelWorkflowInstance(
                instanceId: (string) $this->argument('instance'),
                actorEmployeeId: $this->option('as') === null ? null : (string) $this->option('as'),
                comment: $this->option('comment') === null ? null : (string) $this->option('comment'),
            )));
        } catch (WorkflowActionRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Instance {$state->id} is now [{$state->status->value}].");

        return self::SUCCESS;
    }
}
