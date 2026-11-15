<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

use DateTimeImmutable;

/**
 * The read `instance()`, `decide()` and `cancel()` all return (T-020 Ruling
 * 2, Ruling 7, AC-12, AC-19).
 */
final readonly class WorkflowInstanceState
{
    public function __construct(
        public string $id,
        public string $definitionKey,
        public string $entityType,
        public string $entityId,
        public WorkflowInstanceStatus $status,
        public int $currentStep,
        public WorkflowOnReject $onReject,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt = null,
        public ?int $unassignedStep = null,
    ) {}
}
