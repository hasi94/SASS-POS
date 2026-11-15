<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Engine;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Workflow\Definitions\ValidatedStep;
use Flexipos\Workflow\Support\TaskStatus;
use Flexipos\Workflow\Support\TenantConnection;
use Flexipos\Workflow\Support\WorkflowEventLog;
use Flexipos\Workflow\Support\WorkflowEventType;
use Illuminate\Support\Str;

/**
 * Activate one step: resolve its approvers, write one `workflow_tasks` row
 * per approver and one `assigned` event per task (T-020 Ruling 7, AC-9,
 * AC-12).
 *
 * **When the resolved set is empty, nothing is written here.** The
 * instance stays pending at that step; the caller is the one that records
 * `unassignable_step` on its own event (the `started` event for step 1,
 * the vote's own event for a later step), because the event vocabulary has
 * no event of its own for "a step with nobody assigned". {@see
 * self::resolve()} and {@see self::writeTasks()} are split apart for
 * exactly that reason: a caller that must know the outcome before it
 * writes its own event (step 1, inside `evaluate()`) resolves first and
 * writes tasks after.
 */
final class StepActivator
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ApproverResolver $resolver,
        private readonly WorkflowEventLog $events,
        private readonly RunnerClock $clock,
    ) {}

    /**
     * @return list<array{employeeId: string, roleId: ?string}>
     */
    public function resolve(ValidatedStep $step, ?string $branchId, string $requesterId): array
    {
        return $this->resolver->resolve($step->approverType, $step->approverRef, $branchId, $requesterId);
    }

    /**
     * @param  list<array{employeeId: string, roleId: ?string}>  $resolved
     * @return list<array{taskId: string, employeeId: string, roleId: ?string}>
     */
    public function writeTasks(string $instanceId, ValidatedStep $step, array $resolved): array
    {
        $now = $this->clock->timestamp();
        $assignments = [];

        foreach ($resolved as $approver) {
            $taskId = (string) Str::ulid();

            $this->tenant->get()->table('workflow_tasks')->insert([
                'id' => $taskId,
                'instance_id' => $instanceId,
                'step_sequence' => $step->sequence,
                'assignee_employee_id' => $approver['employeeId'],
                'assignee_role_id' => $approver['roleId'],
                'status' => TaskStatus::Pending->value,
                'decided_by' => null,
                'decided_at' => null,
                'comment' => null,
                'delegated_to' => null,
                // The notification/reminder columns are deliberately never
                // written here (AC-22, D-9): they stay NULL, their
                // shipped default.
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->events->record($instanceId, WorkflowEventType::Assigned, taskId: $taskId);

            $assignments[] = ['taskId' => $taskId, 'employeeId' => $approver['employeeId'], 'roleId' => $approver['roleId']];
        }

        return $assignments;
    }

    /**
     * Resolve and write in one call, for a caller with no ordering
     * constraint of its own (`decide()`'s step advance).
     *
     * @return list<array{taskId: string, employeeId: string, roleId: ?string}>
     */
    public function activate(string $instanceId, ValidatedStep $step, ?string $branchId, string $requesterId): array
    {
        return $this->writeTasks($instanceId, $step, $this->resolve($step, $branchId, $requesterId));
    }
}
