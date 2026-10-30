<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Engine;

use Flexipos\Contracts\Workflow\WorkflowApprovals;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowDecision;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Contracts\Workflow\WorkflowInstanceState;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Workflow\Features\CancelWorkflowInstance\CancelWorkflowInstance;
use Flexipos\Workflow\Features\CancelWorkflowInstance\CancelWorkflowInstanceHandler;
use Flexipos\Workflow\Features\DecideWorkflowTask\DecideWorkflowTask;
use Flexipos\Workflow\Features\DecideWorkflowTask\DecideWorkflowTaskHandler;
use Flexipos\Workflow\Features\EvaluateWorkflow\EvaluateWorkflow;
use Flexipos\Workflow\Features\EvaluateWorkflow\EvaluateWorkflowHandler;
use Flexipos\Workflow\Support\TenantConnection;
use Throwable;

/**
 * The implementation of both {@see WorkflowGate} and {@see WorkflowApprovals}
 * (T-020 Ruling 3).
 *
 * **`evaluate()` does exactly three things** (Ruling 3): opens one tenant
 * transaction (a savepoint if the caller already holds one), runs
 * {@see EvaluateWorkflowHandler} inside it, and catches every `Throwable`
 * to rethrow it as `WorkflowEvaluationFailed` - the one and only catch on
 * this path. **No code path here returns "no approval required" from a
 * catch.**
 */
final class WorkflowEngine implements WorkflowApprovals, WorkflowGate
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly EvaluateWorkflowHandler $evaluateHandler,
        private readonly DecideWorkflowTaskHandler $decideHandler,
        private readonly CancelWorkflowInstanceHandler $cancelHandler,
        private readonly InstanceReader $reader,
    ) {}

    public function evaluate(WorkflowEntityType $entityType, WorkflowContext $context): WorkflowDecision
    {
        try {
            return $this->tenant->get()->transaction(
                fn (): WorkflowDecision => $this->evaluateHandler->handle(new EvaluateWorkflow($entityType, $context))
            );
        } catch (Throwable $exception) {
            throw WorkflowEvaluationFailed::engineError($exception);
        }
    }

    public function instance(string $instanceId): WorkflowInstanceState
    {
        return $this->reader->read($instanceId);
    }

    public function cancel(string $instanceId, ?string $actorEmployeeId = null, ?string $comment = null): WorkflowInstanceState
    {
        return $this->cancelHandler->handle(new CancelWorkflowInstance($instanceId, $actorEmployeeId, $comment));
    }

    public function decide(string $taskId, string $actorEmployeeId, WorkflowVerdict $verdict, ?string $comment = null): WorkflowInstanceState
    {
        return $this->decideHandler->handle(new DecideWorkflowTask($taskId, $actorEmployeeId, $verdict, $comment));
    }
}
