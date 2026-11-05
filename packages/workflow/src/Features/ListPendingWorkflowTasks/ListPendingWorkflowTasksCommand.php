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

        $this->table(
            ['Task', 'Instance', 'Definition', 'Step', 'Assignee', 'Status', 'Created'],
            array_map(static fn (object $row): array => [
                $row->task, $row->instance_id, $row->definition, $row->step_sequence, $row->assignee, $row->status, $row->created_at,
            ], $rows)
        );

        return self::SUCCESS;
    }
}
