<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\DecideWorkflowTask;

use Flexipos\Contracts\Workflow\WorkflowVerdict;

final readonly class DecideWorkflowTask
{
    public function __construct(
        public string $taskId,
        public string $actorEmployeeId,
        public WorkflowVerdict $verdict,
        public ?string $comment = null,
    ) {}
}
