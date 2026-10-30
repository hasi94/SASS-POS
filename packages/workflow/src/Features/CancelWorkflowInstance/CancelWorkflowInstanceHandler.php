<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\CancelWorkflowInstance;

use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowInstanceState;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Workflow\Engine\InstanceReader;
use Flexipos\Workflow\Support\TaskStatus;
use Flexipos\Workflow\Support\TenantConnection;
use Flexipos\Workflow\Support\WorkflowEventLog;
use Flexipos\Workflow\Support\WorkflowEventType;

/**
 * `cancel()`'s own logic (T-020 Ruling 9, AC-21).
 */
final class CancelWorkflowInstanceHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly WorkflowEventLog $events,
        private readonly InstanceReader $reader,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(CancelWorkflowInstance $command): WorkflowInstanceState
    {
        return $this->tenant->get()->transaction(function () use ($command): WorkflowInstanceState {
            $instance = $this->tenant->get()->table('workflow_instances')
                ->where('id', $command->instanceId)
                ->lockForUpdate()
                ->first();

            if ($instance === null) {
                throw WorkflowActionRefused::unknownInstance($command->instanceId);
            }

            if ((string) $instance->status !== 'pending') {
                throw WorkflowActionRefused::instanceClosed((string) $instance->status);
            }

            $now = $this->clock->timestamp();

            $skippedIds = $this->tenant->get()->table('workflow_tasks')
                ->where('instance_id', $command->instanceId)
                ->where('status', TaskStatus::Pending->value)
                ->pluck('id')
                ->all();

            $skippedIds = array_map(static fn (mixed $id): string => (string) $id, $skippedIds);

            if ($skippedIds !== []) {
                $this->tenant->get()->table('workflow_tasks')
                    ->whereIn('id', $skippedIds)
                    ->update(['status' => TaskStatus::Skipped->value, 'updated_at' => $now]);
            }

            $this->tenant->get()->table('workflow_instances')->where('id', $command->instanceId)->update([
                'status' => 'cancelled',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

            $this->events->record(
                $command->instanceId,
                WorkflowEventType::Cancelled,
                actorEmployeeId: $command->actorEmployeeId,
                comment: $command->comment,
                data: ['skipped_task_ids' => $skippedIds],
            );

            return $this->reader->read($command->instanceId);
        });
    }
}
