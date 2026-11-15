<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\RegisterWorkflowDefinition;

final readonly class RegisterWorkflowDefinition
{
    public function __construct(
        public string $json,
        public ?string $registeredBy = null,
    ) {}
}
