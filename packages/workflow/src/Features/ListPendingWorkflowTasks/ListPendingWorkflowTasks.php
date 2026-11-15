<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\ListPendingWorkflowTasks;

final readonly class ListPendingWorkflowTasks
{
    public function __construct(
        public ?string $assignee = null,
    ) {}
}
