<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\ListPendingWorkflowTasks;

use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * `php artisan workflow:pending {--tenant=} {--assignee=}` (T-020 AC-24).
 */
final class ListPendingWorkflowTasksCommand extends Command
{
    protected $signature = 'workflow:pending {--tenant= : The tenant id} {--assignee= : Filter to one assignee}';

    protected $description = 'List pending workflow tasks for a tenant';

    public function handle(ListPendingWorkflowTasksHandler $handler, Tenancy $tenancy): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId === null) {
            $this->components->error('--tenant is required.');

            return self::FAILURE;
        }

        $assignee = $this->option('assignee');

        $rows = $tenancy->run((string) $tenantId, fn () => $handler->handle(new ListPendingWorkflowTasks(
            assignee: $assignee === null ? null : (string) $assignee,
        )));

        foreach ($rows as $row) {
            $this->components->info(
                "{$row->task} ({$row->definition}, step {$row->step_sequence}): {$row->assignee} [{$row->status}], created {$row->created_at}"
            );
        }

        return self::SUCCESS;
    }
}
