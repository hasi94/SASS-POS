<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\EvaluateWorkflow;

use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;

final readonly class EvaluateWorkflow
{
    public function __construct(
        public WorkflowEntityType $entityType,
        public WorkflowContext $context,
    ) {}
}
