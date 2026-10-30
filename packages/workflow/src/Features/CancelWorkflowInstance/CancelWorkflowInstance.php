<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\CancelWorkflowInstance;

final readonly class CancelWorkflowInstance
{
    public function __construct(
        public string $instanceId,
        public ?string $actorEmployeeId = null,
        public ?string $comment = null,
    ) {}
}
