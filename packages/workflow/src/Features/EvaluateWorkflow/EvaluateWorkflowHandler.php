<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\EvaluateWorkflow;

use Flexipos\Contracts\Workflow\WorkflowDecision;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Contracts\Workflow\WorkflowOutcome;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Workflow\Engine\DefinitionSelector;
use Flexipos\Workflow\Engine\StepActivator;
use Flexipos\Workflow\Support\DefinitionRepository;
use Flexipos\Workflow\Support\TenantConnection;
use Flexipos\Workflow\Support\WorkflowEventLog;
use Flexipos\Workflow\Support\WorkflowEventType;
use Illuminate\Support\Str;

/**
 * `evaluate()`'s own logic, run inside the one tenant transaction
 * `WorkflowEngine::evaluate()` opens (T-020 Ruling 3, AC-6, AC-9, AC-14 to
 * AC-16).
 *
 * **No `catch` block anywhere in this file.** Every error this handler
 * cannot itself resolve propagates out of `handle()` unmodified, so the
 * one catch-all in `WorkflowEngine::evaluate()` is the only place an
 * exception is ever intercepted on this path (`QaWorkflowSourceRulesTest`
 * enforces this by source scan).
 */
final class EvaluateWorkflowHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly DefinitionSelector $selector,
        private readonly DefinitionRepository $definitions,
        private readonly StepActivator $activator,
        private readonly WorkflowEventLog $events,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(EvaluateWorkflow $command): WorkflowDecision
    {
        $entityType = $command->entityType;
        $context = $command->context;

        $existing = $this->tenant->get()->table('workflow_instances')
            ->where('entity_type', $entityType->value)
            ->where('entity_id', $context->entityId)
            ->where('status', 'pending')
            ->first();

        if ($existing !== null) {
            throw WorkflowEvaluationFailed::alreadyPending((string) $existing->id);
        }

        $definition = $this->selector->select($entityType, $context);

        if ($definition === null) {
            return new WorkflowDecision(WorkflowOutcome::NotRequired);
        }

        $steps = $this->definitions->stepsOf((string) $definition->id, (string) $definition->key);
        $firstStep = $steps[0];

        $instanceId = (string) Str::ulid();
        $now = $this->clock->timestamp();

        $resolved = $this->activator->resolve($firstStep, $context->branchId, $context->requestedBy);

        $frozenContext = array_filter($context->values(), static fn (mixed $value): bool => $value !== null);

        $this->tenant->get()->table('workflow_instances')->insert([
            'id' => $instanceId,
            'definition_id' => $definition->id,
            'definition_version' => $definition->version,
            'entity_type' => $entityType->value,
            'entity_id' => $context->entityId,
            'branch_id' => $context->branchId,
            'requested_by' => $context->requestedBy,
            'amount' => $context->amount,
            'current_step' => $firstStep->sequence,
            'status' => 'pending',
            'started_at' => $now,
            'completed_at' => null,
            // The SLA due column is deliberately never written here
            // (AC-22, D-9): it stays NULL, its shipped default.
            'context' => json_encode($frozenContext),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $startedData = $resolved === [] ? ['unassignable_step' => $firstStep->sequence] : [];

        $this->events->record($instanceId, WorkflowEventType::Started, actorEmployeeId: $context->requestedBy, data: $startedData);

        if ($resolved !== []) {
            $this->activator->writeTasks($instanceId, $firstStep, $resolved);
        }

        $onReject = $this->definitions->onRejectOf($definition);

        return new WorkflowDecision(WorkflowOutcome::Pending, $instanceId, (string) $definition->key, $onReject);
    }
}
