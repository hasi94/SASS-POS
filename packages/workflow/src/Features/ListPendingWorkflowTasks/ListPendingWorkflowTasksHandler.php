<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\ListPendingWorkflowTasks;

use Flexipos\Workflow\Support\TenantConnection;

/**
 * `workflow:pending` (T-020 AC-24). Pending tasks on pending instances
 * only, ordered by (`created_at`, `id`).
 */
final class ListPendingWorkflowTasksHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @return list<object>
     */
    public function handle(ListPendingWorkflowTasks $query): array
    {
        $builder = $this->tenant->get()->table('workflow_tasks')
            ->join('workflow_instances', 'workflow_instances.id', '=', 'workflow_tasks.instance_id')
            ->join('workflow_definitions', 'workflow_definitions.id', '=', 'workflow_instances.definition_id')
            ->where('workflow_tasks.status', 'pending')
            ->where('workflow_instances.status', 'pending');

        if ($query->assignee !== null) {
            $builder->where('workflow_tasks.assignee_employee_id', $query->assignee);
        }

        return $builder
            ->orderBy('workflow_tasks.created_at')
            ->orderBy('workflow_tasks.id')
            ->get([
                'workflow_tasks.id as task',
                'workflow_instances.id as instance_id',
                'workflow_definitions.key as definition',
                'workflow_tasks.step_sequence as step_sequence',
                'workflow_tasks.assignee_employee_id as assignee',
                'workflow_tasks.status as status',
                'workflow_tasks.created_at as created_at',
            ])
            ->all();
    }
}
