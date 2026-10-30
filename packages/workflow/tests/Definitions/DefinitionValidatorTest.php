<?php

use Flexipos\Workflow\Definitions\DefinitionValidator;
use Flexipos\Workflow\Exceptions\WorkflowDefinitionRefused;
use Flexipos\Workflow\Tests\Definitions\FakeEmployeeDirectory;
use Flexipos\Workflow\Tests\Fixtures\FixtureApproval;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  list<string>  $liveEmployees
 */
function wfValidator(array $liveEmployees = []): DefinitionValidator
{
    return new DefinitionValidator(new FakeEmployeeDirectory($liveEmployees));
}

function wfRoleId(): string
{
    return (string) Str::ulid();
}

function wfRefusalReason(callable $call): string
{
    try {
        $call();
    } catch (WorkflowDefinitionRefused $refused) {
        return $refused->reason;
    }

    throw new RuntimeException('Expected a WorkflowDefinitionRefused.');
}

it('refuses malformed JSON', function () {
    expect(wfRefusalReason(fn () => wfValidator()->validate('{not json')))->toBe('malformedJson');
});

it('refuses each missing required field, through a dataset', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);

    foreach (['key', 'name', 'entity_type', 'priority', 'on_reject', 'conditions', 'steps'] as $field) {
        $broken = $base;
        unset($broken[$field]);

        expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($broken))))->toBe('missingField');
    }
});

it('refuses an unknown top-level field', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);
    $base['unknown_thing'] = 'x';

    expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($base))))->toBe('unknownField');
});

it('refuses an unknown entity_type', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);

    foreach (['Custom', 'widget'] as $bad) {
        $broken = $base;
        $broken['entity_type'] = $bad;

        expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($broken))))->toBe('unknownEntityType');
    }
});

it('refuses in with a non-list value, control: a list is accepted (AC-8)', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);

    foreach (['a', [], new stdClass] as $badValue) {
        $broken = $base;
        $broken['conditions'] = [['field' => 'amount', 'operator' => 'in', 'value' => $badValue]];

        expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($broken))))->toBe('listValueRequired');
    }

    $good = $base;
    $good['conditions'] = [['field' => 'amount', 'operator' => 'in', 'value' => ['1000']]];

    expect(wfValidator()->validate(json_encode($good)))->not->toBeNull();
});

it('refuses a non-numeric value for an ordering operator, and a float value', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);

    $nonNumeric = $base;
    $nonNumeric['conditions'] = [['field' => 'amount', 'operator' => '>', 'value' => 'abc']];

    expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($nonNumeric))))->toBe('numericValueRequired');

    $float = $base;
    $float['conditions'] = [['field' => 'amount', 'operator' => '>', 'value' => 1.5]];

    expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($float))))->toBe('invalidField');
});

it('refuses a duplicate sequence and a sequence gap (AC-3)', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);

    $duplicate = $base;
    $duplicate['steps'][1]['sequence'] = 1;

    expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($duplicate))))->toBe('duplicateSequence');

    $gap = $base;
    $gap['steps'][1]['sequence'] = 3;

    expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($gap))))->toBe('sequenceGap');
});

it('refuses a definition with no steps', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);
    $base['steps'] = [];

    expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($base))))->toBe('noSteps');
});

it('refuses role with an employees ref', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);
    $base['steps'][0]['approver_ref'] = ['roles' => [wfRoleId()], 'employees' => [wfRoleId()]];

    expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($base))))->toBe('invalidField');
});

it('refuses any_of with quorum all (quorumContradictsApproverType)', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);
    $base['steps'][0]['approver_type'] = 'any_of';
    $base['steps'][0]['quorum'] = 'all';

    expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($base))))->toBe('quorumContradictsApproverType');
});

it('refuses the three unresolvable approver types (Ruling 6c)', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);

    foreach (['manager_of_requester', 'department_head', 'branch_manager'] as $type) {
        $broken = $base;
        $broken['steps'][0]['approver_type'] = $type;

        expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($broken))))->toBe('approverTypeUnavailable');
    }
});

it('refuses an employee who is not live', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);
    $employeeId = wfRoleId();
    $base['steps'][0]['approver_type'] = 'employee';
    $base['steps'][0]['approver_ref'] = ['employees' => [$employeeId]];

    expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($base))))->toBe('unknownEmployee');

    $validated = wfValidator([$employeeId])->validate(json_encode($base));

    expect($validated->steps[0]->approverRef['employees'])->toBe([$employeeId]);
});

it('refuses a can_skip_if with a bad operator', function () {
    $base = json_decode(FixtureApproval::json(wfRoleId(), wfRoleId()), true);
    $base['steps'][0]['can_skip_if'] = [['field' => 'amount', 'operator' => 'contains', 'value' => '1']];

    expect(wfRefusalReason(fn () => wfValidator()->validate(json_encode($base))))->toBe('unknownOperator');
});

it('validates the real FixtureApproval JSON', function () {
    $validated = wfValidator()->validate(FixtureApproval::json(wfRoleId(), wfRoleId()));

    expect($validated->key)->toBe('fixture-approval')
        ->and($validated->steps)->toHaveCount(2);
});
