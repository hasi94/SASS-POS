<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\ShowWorkflowHistory;

use Flexipos\Contracts\Workflow\WorkflowInstanceState;

final readonly class ShowWorkflowHistoryResult
{
    /**
     * @param  list<object>  $events
     */
    public function __construct(
        public WorkflowInstanceState $state,
        public array $events,
    ) {}
}
