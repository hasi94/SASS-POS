<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Definitions;

use Flexipos\Contracts\Workflow\WorkflowOnReject;
use Flexipos\Workflow\Conditions\Condition;

/**
 * A fully validated definition, ready to be written or matched against
 * (T-020 Ruling 10).
 */
final readonly class ValidatedDefinition
{
    /**
     * @param  list<Condition>  $conditions
     * @param  list<string>|null  $branchIds
     * @param  list<ValidatedStep>  $steps
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $entityType,
        public int $priority,
        public WorkflowOnReject $onReject,
        public array $conditions,
        public array $steps,
        public bool $isActive = true,
        public ?array $branchIds = null,
        public ?int $escalationHours = null,
    ) {}
}
