<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\DecideWorkflowTask;

use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowInstanceState;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Workflow\Engine\InstanceReader;
use Flexipos\Workflow\Engine\QuorumRule;
use Flexipos\Workflow\Engine\StepActivator;
use Flexipos\Workflow\Engine\StepState;
use Flexipos\Workflow\Support\DefinitionRepository;
use Flexipos\Workflow\Support\TaskStatus;
use Flexipos\Workflow\Support\TenantConnection;
use Flexipos\Workflow\Support\WorkflowEventLog;
use Flexipos\Workflow\Support\WorkflowEventType;

/**
 * `decide()`'s own logic, run inside one tenant transaction (T-020 Ruling
 * 8, AC-10, AC-11, AC-12, AC-17 to AC-20).
 */
final class DecideWorkflowTaskHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly DefinitionRepository $definitions,
        private readonly StepActivator $activator,
        private readonly WorkflowEventLog $events,
        private readonly InstanceReader $reader,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(DecideWorkflowTask $command): WorkflowInstanceState
    {
        return $this->tenant->get()->transaction(function () use ($command): WorkflowInstanceState {
            $task = $this->tenant->get()->table('workflow_tasks')->where('id', $command->taskId)->first();

            if ($task === null) {
                throw WorkflowActionRefused::unknownTask($command->taskId);
            }

            $instance = $this->tenant->get()->table('workflow_instances')
                ->where('id', $task->instance_id)
                ->lockForUpdate()
                ->first();

            if ($instance === null) {
                throw WorkflowActionRefused::unknownInstance((string) $task->instance_id);
            }

            if ((string) $instance->status !== 'pending') {
                throw WorkflowActionRefused::instanceClosed((string) $instance->status);
            }

            if ((string) $task->status !== 'pending') {
                throw WorkflowActionRefused::taskNotPending((string) $task->status);
            }

            if ((string) $task->assignee_employee_id !== $command->actorEmployeeId) {
                throw WorkflowActionRefused::notAssignee($command->actorEmployeeId);
            }

            $now = $this->clock->timestamp();
            $newStatus = $command->verdict === WorkflowVerdict::Approve ? TaskStatus::Approved : TaskStatus::Rejected;

            $affected = $this->tenant->get()->table('workflow_tasks')
                ->where('id', $command->taskId)
                ->where('status', 'pending')
                ->update([
                    'status' => $newStatus->value,
                    'decided_by' => $command->actorEmployeeId,
                    'decided_at' => $now,
                    'comment' => $command->comment,
                    'updated_at' => $now,
                ]);

            if ($affected === 0) {
                throw WorkflowActionRefused::taskNotPending((string) $task->status);
            }

            $definition = $this->tenant->get()->table('workflow_definitions')->where('id', $instance->definition_id)->first();
            $steps = $this->definitions->stepsOf((string) $definition->id, (string) $definition->key);
            $currentStep = null;
            $nextStep = null;

            foreach ($steps as $step) {
                if ($step->sequence === (int) $task->step_sequence) {
                    $currentStep = $step;
                } elseif ($step->sequence === (int) $task->step_sequence + 1) {
                    $nextStep = $step;
                }
            }

            $stepTasks = $this->tenant->get()->table('workflow_tasks')
                ->where('instance_id', $instance->id)
                ->where('step_sequence', $task->step_sequence)
                ->get();

            $n = $stepTasks->count();
            $approved = $stepTasks->where('status', TaskStatus::Approved->value)->count();
            $rejected = $stepTasks->where('status', TaskStatus::Rejected->value)->count();

            $state = QuorumRule::state($currentStep->quorum, $n, $approved, $rejected);

            $eventType = $command->verdict === WorkflowVerdict::Approve ? WorkflowEventType::Approved : WorkflowEventType::Rejected;

            if ($state === StepState::Open) {
                $this->events->record((string) $instance->id, $eventType, taskId: $command->taskId, actorEmployeeId: $command->actorEmployeeId, comment: $command->comment);

                return $this->reader->read((string) $instance->id);
            }

            $skippedIds = $this->skipPendingTasksAt((string) $instance->id, (int) $task->step_sequence, $now);

            if ($state === StepState::Satisfied && $nextStep !== null) {
                $resolved = $this->activator->resolve($nextStep, $instance->branch_id, (string) $instance->requested_by);

                $eventData = [
                    'skipped_task_ids' => $skippedIds,
                    'advanced_to' => $nextStep->sequence,
                ];

                if ($resolved === []) {
                    $eventData['unassignable_step'] = $nextStep->sequence;
                }

                $this->events->record((string) $instance->id, $eventType, taskId: $command->taskId, actorEmployeeId: $command->actorEmployeeId, comment: $command->comment, data: $eventData);

                $this->tenant->get()->table('workflow_instances')->where('id', $instance->id)->update([
                    'current_step' => $nextStep->sequence,
                    'updated_at' => $now,
                ]);

                if ($resolved !== []) {
                    $this->activator->writeTasks((string) $instance->id, $nextStep, $resolved);
                }

                return $this->reader->read((string) $instance->id);
            }

            // Terminal: approved (last step satisfied) or rejected.
            $this->events->record((string) $instance->id, $eventType, taskId: $command->taskId, actorEmployeeId: $command->actorEmployeeId, comment: $command->comment);

            $finalStatus = $state === StepState::Satisfied ? 'approved' : 'rejected';

            $this->tenant->get()->table('workflow_instances')->where('id', $instance->id)->update([
                'status' => $finalStatus,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

            $completedData = ['status' => $finalStatus, 'skipped_task_ids' => $skippedIds];

            if ($finalStatus === 'rejected') {
                $completedData['on_reject'] = $this->definitions->onRejectOf($definition)->value;
            }

            $this->events->record((string) $instance->id, WorkflowEventType::Completed, actorEmployeeId: $command->actorEmployeeId, data: $completedData);

            return $this->reader->read((string) $instance->id);
        });
    }

    /**
     * @return list<string>
     */
    private function skipPendingTasksAt(string $instanceId, int $stepSequence, string $now): array
    {
        $ids = $this->tenant->get()->table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->where('step_sequence', $stepSequence)
            ->where('status', TaskStatus::Pending->value)
            ->pluck('id')
            ->all();

        $ids = array_map(static fn (mixed $id): string => (string) $id, $ids);

        if ($ids !== []) {
            $this->tenant->get()->table('workflow_tasks')
                ->whereIn('id', $ids)
                ->update(['status' => TaskStatus::Skipped->value, 'updated_at' => $now]);
        }

        return $ids;
    }
}
