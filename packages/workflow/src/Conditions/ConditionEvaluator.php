<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Conditions;

use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;

/**
 * A pure function: does a flat AND of conditions match a context's values
 * (T-020 Ruling 4, AC-5 to AC-7)?
 */
final class ConditionEvaluator
{
    /**
     * @param  list<Condition>  $conditions
     * @param  array<string, string|int|bool|null>  $values
     *
     * @throws WorkflowEvaluationFailed `conditionNotEvaluable`
     */
    public static function matches(array $conditions, array $values, string $definitionKey): bool
    {
        // AC-7: an empty list always matches.
        foreach ($conditions as $condition) {
            if (! self::matchesOne($condition, $values, $definitionKey)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    private static function matchesOne(Condition $condition, array $values, string $definitionKey): bool
    {
        if (! array_key_exists($condition->field, $values) || $values[$condition->field] === null) {
            throw WorkflowEvaluationFailed::conditionNotEvaluable($definitionKey, $condition->field, 'missing');
        }

        $actual = $values[$condition->field];

        if ($condition->operator->isListOperator()) {
            /** @var list<string|int|bool> $list */
            $list = (array) $condition->value;
            $isIn = false;

            foreach ($list as $candidate) {
                if (self::equal($actual, $candidate, $condition, $definitionKey)) {
                    $isIn = true;

                    break;
                }
            }

            return $condition->operator === ConditionOperator::In ? $isIn : ! $isIn;
        }

        if ($condition->operator->isOrdering()) {
            if (! self::isNumeric($actual) || ! self::isNumeric($condition->value)) {
                throw WorkflowEvaluationFailed::conditionNotEvaluable($definitionKey, $condition->field, 'not numeric');
            }

            /** @var int|string $actual */
            /** @var int|string $expected */
            $expected = $condition->value;
            $comparison = DecimalComparator::compare($actual, $expected);

            return match ($condition->operator) {
                ConditionOperator::GreaterThan => $comparison > 0,
                ConditionOperator::GreaterThanOrEqual => $comparison >= 0,
                ConditionOperator::LessThan => $comparison < 0,
                ConditionOperator::LessThanOrEqual => $comparison <= 0,
                default => false,
            };
        }

        $equal = self::equal($actual, $condition->value, $condition, $definitionKey);

        return $condition->operator === ConditionOperator::Equal ? $equal : ! $equal;
    }

    private static function equal(string|int|bool $actual, mixed $expected, Condition $condition, string $definitionKey): bool
    {
        if (self::isNumeric($actual) && self::isNumeric($expected)) {
            /** @var int|string $numericActual */
            $numericActual = $actual;
            /** @var int|string $numericExpected */
            $numericExpected = $expected;

            return DecimalComparator::equal($numericActual, $numericExpected);
        }

        if (is_string($actual) && is_string($expected)) {
            return $actual === $expected;
        }

        if (is_bool($actual) && is_bool($expected)) {
            return $actual === $expected;
        }

        throw WorkflowEvaluationFailed::conditionNotEvaluable($definitionKey, $condition->field, 'type mismatch');
    }

    private static function isNumeric(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1;
    }
}
