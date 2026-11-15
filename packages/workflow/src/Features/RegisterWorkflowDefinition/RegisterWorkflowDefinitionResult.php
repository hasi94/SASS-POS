<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\RegisterWorkflowDefinition;

final readonly class RegisterWorkflowDefinitionResult
{
    public function __construct(
        public string $id,
        public string $key,
        public int $stepCount,
    ) {}
}
