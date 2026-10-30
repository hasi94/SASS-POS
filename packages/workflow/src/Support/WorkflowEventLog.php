<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;

/**
 * The **only** writer of `workflow_events`, and it only inserts (T-020
 * Ruling 8). `QaWorkflowSourceRulesTest` enforces both halves of that
 * sentence.
 */
final class WorkflowEventLog
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(
        string $instanceId,
        WorkflowEventType $event,
        ?string $taskId = null,
        ?string $actorEmployeeId = null,
        ?string $comment = null,
        array $data = [],
    ): void {
        $this->tenant->get()->table('workflow_events')->insert([
            'instance_id' => $instanceId,
            'task_id' => $taskId,
            'event' => $event->value,
            'actor_employee_id' => $actorEmployeeId,
            'comment' => $comment,
            'data' => $data === [] ? null : json_encode($data),
            'created_at' => $this->clock->timestamp(),
        ]);
    }

    public static function newId(): string
    {
        return (string) Str::ulid();
    }
}
