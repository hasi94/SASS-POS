<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Engine;

use DateTimeImmutable;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowInstanceState;
use Flexipos\Contracts\Workflow\WorkflowInstanceStatus;
use Flexipos\Workflow\Support\DefinitionRepository;
use Flexipos\Workflow\Support\TenantConnection;

/**
 * Read one instance's current state (T-020 Ruling 7, AC-12, AC-19).
 */
final class InstanceReader
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly DefinitionRepository $definitions,
    ) {}

    /**
     * @throws WorkflowActionRefused `unknownInstance`
     */
    public function read(string $instanceId): WorkflowInstanceState
    {
        $row = $this->row($instanceId);
        $definition = $this->tenant->get()->table('workflow_definitions')->where('id', $row->definition_id)->first();

        $status = WorkflowInstanceStatus::from((string) $row->status);
        $unassignedStep = null;

        if ($status === WorkflowInstanceStatus::Pending) {
            $tasksAtCurrentStep = $this->tenant->get()->table('workflow_tasks')
                ->where('instance_id', $instanceId)
                ->where('step_sequence', $row->current_step)
                ->count();

            if ($tasksAtCurrentStep === 0) {
                $unassignedStep = (int) $row->current_step;
            }
        }

        return new WorkflowInstanceState(
            id: (string) $row->id,
            definitionKey: (string) $definition->key,
            entityType: (string) $row->entity_type,
            entityId: (string) $row->entity_id,
            status: $status,
            currentStep: (int) $row->current_step,
            onReject: $this->definitions->onRejectOf($definition),
            startedAt: new DateTimeImmutable((string) $row->started_at),
            completedAt: $row->completed_at === null ? null : new DateTimeImmutable((string) $row->completed_at),
            unassignedStep: $unassignedStep,
        );
    }

    /**
     * @throws WorkflowActionRefused `unknownInstance`
     */
    public function row(string $instanceId): object
    {
        $row = $this->tenant->get()->table('workflow_instances')->where('id', $instanceId)->first();

        if ($row === null) {
            throw WorkflowActionRefused::unknownInstance($instanceId);
        }

        return $row;
    }
}
