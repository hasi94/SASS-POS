<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

/**
 * The approver-side API of the workflow engine (T-020 Ruling 2, AC-17).
 *
 * Implemented by `Flexipos\Workflow\Engine\WorkflowEngine`, the same class
 * as {@see WorkflowGate}. Published separately so a future approver inbox
 * never has to import the engine's internals to record a decision.
 */
interface WorkflowApprovals
{
    /**
     * Approve or reject one task (AC-17 to AC-20).
     *
     * @throws WorkflowActionRefused `unknownTask`, `instanceClosed`,
     *                               `taskNotPending`, `notAssignee`
     */
    public function decide(string $taskId, string $actorEmployeeId, WorkflowVerdict $verdict, ?string $comment = null): WorkflowInstanceState;
}
