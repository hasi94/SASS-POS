<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Conditions;

/**
 * The eight comparisons a condition may use (T-020 AC-5, D-5).
 */
enum ConditionOperator: string
{
    case Equal = '=';
    case NotEqual = '!=';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
    case In = 'in';
    case NotIn = 'not_in';

    public function isOrdering(): bool
    {
        return in_array($this, [self::GreaterThan, self::GreaterThanOrEqual, self::LessThan, self::LessThanOrEqual], true);
    }

    public function isListOperator(): bool
    {
        return in_array($this, [self::In, self::NotIn], true);
    }
}
