<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Conditions;

/**
 * One stored `{"field", "operator", "value"}` entry (T-020 Ruling 4).
 */
final readonly class Condition
{
    /**
     * @param  string|int|bool|list<string|int|bool>  $value
     */
    public function __construct(
        public string $field,
        public ConditionOperator $operator,
        public string|int|bool|array $value,
    ) {}
}
