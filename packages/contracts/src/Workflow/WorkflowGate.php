<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

/**
 * The requester-side API of the workflow engine (T-020 AC-13, D-2).
 *
 * Standalone and fail-closed (D-2, D-3): called directly by whichever
 * module owns the gated entity, never through `HookGateway::dispatch()`.
 * Implemented by `Flexipos\Workflow\Engine\WorkflowEngine`.
 */
interface WorkflowGate
{
    /**
     * Decide whether this entity+context needs approval right now, and
     * open the chain if it does.
     *
     * @throws WorkflowEvaluationFailed on anything that stops evaluation
     *                                  from completing. The caller's
     *                                  gated action must not proceed past
     *                                  this exception.
     */
    public function evaluate(WorkflowEntityType $entityType, WorkflowContext $context): WorkflowDecision;

    /**
     * Read one instance's current state.
     *
     * @throws WorkflowActionRefused `unknownInstance`
     */
    public function instance(string $instanceId): WorkflowInstanceState;

    /**
     * Withdraw a still-pending instance (AC-21).
     *
     * @throws WorkflowActionRefused `unknownInstance`, `instanceClosed`
     */
    public function cancel(string $instanceId, ?string $actorEmployeeId = null, ?string $comment = null): WorkflowInstanceState;
}
