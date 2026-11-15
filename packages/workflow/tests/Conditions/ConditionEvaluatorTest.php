<?php

use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Workflow\Conditions\Condition;
use Flexipos\Workflow\Conditions\ConditionEvaluator;
use Flexipos\Workflow\Conditions\ConditionOperator;
use Tests\TestCase;

uses(TestCase::class);

function wfCondition(string $field, ConditionOperator $operator, mixed $value): Condition
{
    return new Condition($field, $operator, $value);
}

it('evaluates each of the eight operators true and false (AC-5)', function () {
    $values = ['amount' => '1000', 'category' => 'stationery', 'flag' => true];

    expect(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::Equal, '1000')], $values, 'k'))->toBeTrue()
        ->and(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::Equal, '999')], $values, 'k'))->toBeFalse()
        ->and(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::NotEqual, '999')], $values, 'k'))->toBeTrue()
        ->and(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::GreaterThan, '500')], $values, 'k'))->toBeTrue()
        ->and(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::GreaterThan, '5000')], $values, 'k'))->toBeFalse()
        ->and(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::GreaterThanOrEqual, '1000')], $values, 'k'))->toBeTrue()
        ->and(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::LessThan, '5000')], $values, 'k'))->toBeTrue()
        ->and(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::LessThanOrEqual, '1000')], $values, 'k'))->toBeTrue()
        ->and(ConditionEvaluator::matches([wfCondition('category', ConditionOperator::In, ['stationery', 'ink'])], $values, 'k'))->toBeTrue()
        ->and(ConditionEvaluator::matches([wfCondition('category', ConditionOperator::NotIn, ['stationery', 'ink'])], $values, 'k'))->toBeFalse();
});

it('ANDs two conditions, one false, giving no match', function () {
    $values = ['amount' => '1000', 'category' => 'stationery'];

    $conditions = [
        wfCondition('amount', ConditionOperator::GreaterThan, '500'),
        wfCondition('category', ConditionOperator::Equal, 'ink'),
    ];

    expect(ConditionEvaluator::matches($conditions, $values, 'k'))->toBeFalse();
});

it('throws conditionNotEvaluable for a missing or null field (AC-6), control: present matches', function () {
    expect(fn () => ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::Equal, '1')], [], 'k'))
        ->toThrow(WorkflowEvaluationFailed::class);

    try {
        ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::Equal, '1')], [], 'k');
    } catch (WorkflowEvaluationFailed $e) {
        expect($e->cause)->toBe('conditionNotEvaluable');
    }

    expect(fn () => ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::Equal, '1')], ['amount' => null], 'k'))
        ->toThrow(WorkflowEvaluationFailed::class);

    expect(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::Equal, '1')], ['amount' => '1'], 'k'))->toBeTrue();
});

it('matches any context with an empty condition list (AC-7)', function () {
    expect(ConditionEvaluator::matches([], [], 'k'))->toBeTrue()
        ->and(ConditionEvaluator::matches([], ['amount' => '1'], 'k'))->toBeTrue();
});

it('throws when an ordering operator meets a non-numeric value', function () {
    expect(fn () => ConditionEvaluator::matches([wfCondition('category', ConditionOperator::GreaterThan, '500')], ['category' => 'stationery'], 'k'))
        ->toThrow(WorkflowEvaluationFailed::class);
});

it('throws on a type mismatch (a bool against a string)', function () {
    expect(fn () => ConditionEvaluator::matches([wfCondition('flag', ConditionOperator::Equal, 'true')], ['flag' => true], 'k'))
        ->toThrow(WorkflowEvaluationFailed::class);
});

it('applies the = rule to each in/not_in element, with mixed numeric and string elements', function () {
    $values = ['amount' => '1000'];

    expect(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::In, [500, '1000', 'x'])], $values, 'k'))->toBeTrue()
        ->and(ConditionEvaluator::matches([wfCondition('amount', ConditionOperator::NotIn, [500, '1000', 'x'])], $values, 'k'))->toBeFalse();
});
