<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\ShowWorkflowHistory;

final readonly class ShowWorkflowHistory
{
    public function __construct(
        public string $instanceId,
    ) {}
}
