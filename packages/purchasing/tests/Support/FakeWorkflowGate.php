<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use DateTimeImmutable;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowDecision;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Contracts\Workflow\WorkflowInstanceState;
use Flexipos\Contracts\Workflow\WorkflowInstanceStatus;
use Flexipos\Contracts\Workflow\WorkflowOnReject;
use Flexipos\Contracts\Workflow\WorkflowOutcome;
use Throwable;

/**
 * A `WorkflowGate` a test controls, for **call shape and failure datasets only**
 * (T-034 test strategy): the exact context `evaluate()` was handed, how many times
 * it was called, and what happens when it throws. The real engine, on the real
 * workflow tables, proves the outcomes.
 *
 * It records every call and answers what it was told to: a decision (or a
 * throwable) for `evaluate()`, a state per instance id for `instance()`, a throwable
 * for `cancel()`.
 */
final class FakeWorkflowGate implements WorkflowGate
{
    /** @var list<array{entityType: WorkflowEntityType, context: WorkflowContext}> */
    public array $evaluations = [];

    /** @var list<string> */
    public array $instanceReads = [];

    /** @var list<array{instanceId: string, actor: ?string, comment: ?string}> */
    public array $cancels = [];

    public WorkflowDecision|Throwable $decision;

    /** @var array<string, WorkflowInstanceState|Throwable> */
    public array $states = [];

    public ?Throwable $cancelFailure = null;

    public function __construct()
    {
        $this->decision = new WorkflowDecision(WorkflowOutcome::NotRequired);
    }

    /**
     * Bind a fresh fake over the container's gate and return it.
     */
    public static function bind(): self
    {
        $fake = new self;

        app()->instance(WorkflowGate::class, $fake);

        return $fake;
    }

    /**
     * An instance state, as the engine would report it.
     */
    public static function state(
        string $instanceId,
        string $entityType,
        string $entityId,
        WorkflowInstanceStatus $status,
        WorkflowOnReject $onReject = WorkflowOnReject::Stop,
    ): WorkflowInstanceState {
        return new WorkflowInstanceState(
            $instanceId,
            'fake-definition',
            $entityType,
            $entityId,
            $status,
            1,
            $onReject,
            new DateTimeImmutable('2026-11-16 09:00:00'),
        );
    }

    public function evaluate(WorkflowEntityType $entityType, WorkflowContext $context): WorkflowDecision
    {
        $this->evaluations[] = ['entityType' => $entityType, 'context' => $context];

        if ($this->decision instanceof Throwable) {
            throw $this->decision;
        }

        return $this->decision;
    }

    public function instance(string $instanceId): WorkflowInstanceState
    {
        $this->instanceReads[] = $instanceId;

        $state = $this->states[$instanceId] ?? throw WorkflowActionRefused::unknownInstance($instanceId);

        if ($state instanceof Throwable) {
            throw $state;
        }

        return $state;
    }

    public function cancel(string $instanceId, ?string $actorEmployeeId = null, ?string $comment = null): WorkflowInstanceState
    {
        $this->cancels[] = ['instanceId' => $instanceId, 'actor' => $actorEmployeeId, 'comment' => $comment];

        if ($this->cancelFailure !== null) {
            throw $this->cancelFailure;
        }

        $state = $this->states[$instanceId] ?? throw WorkflowActionRefused::unknownInstance($instanceId);

        if ($state instanceof Throwable) {
            throw $state;
        }

        return new WorkflowInstanceState(
            $state->id,
            $state->definitionKey,
            $state->entityType,
            $state->entityId,
            WorkflowInstanceStatus::Cancelled,
            $state->currentStep,
            $state->onReject,
            $state->startedAt,
        );
    }
}
