<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

/**
 * What `WorkflowGate::evaluate()` returns (T-020 AC-13, AC-14).
 */
final readonly class WorkflowDecision
{
    public function __construct(
        public WorkflowOutcome $outcome,
        public ?string $instanceId = null,
        public ?string $definitionKey = null,
        public ?WorkflowOnReject $onReject = null,
    ) {}

    public function requiresApproval(): bool
    {
        return $this->outcome === WorkflowOutcome::Pending;
    }
}
