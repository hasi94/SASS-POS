<?php

// strict_types, deliberately, so that the float-amount case below proves
// the constructor's own int|string|null type refuses a float at the
// language level - the same strictness every src/ file in this repository
// declares.
declare(strict_types=1);

use Flexipos\Contracts\Workflow\WorkflowContext;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

function wfUlid(): string
{
    return (string) Str::ulid();
}

/**
 * Hands a value back as `mixed`, so a deliberately illegal literal (a
 * float, where the contract's own type declares int|string|null) reaches
 * the constructor call as a value PHPStan cannot statically reject -
 * the refusal under test is the constructor's own strict_types check, not
 * a static analysis finding.
 */
function wfMixed(mixed $value): mixed
{
    return $value;
}

it('folds entityId, requestedBy and branchId to upper case', function () {
    $entityId = strtolower(wfUlid());
    $requestedBy = strtolower(wfUlid());
    $branchId = strtolower(wfUlid());

    $context = new WorkflowContext($entityId, $requestedBy, $branchId);

    expect($context->entityId)->toBe(strtoupper($entityId))
        ->and($context->requestedBy)->toBe(strtoupper($requestedBy))
        ->and($context->branchId)->toBe(strtoupper($branchId));
});

it('refuses a malformed ULID', function () {
    expect(fn () => new WorkflowContext('not-a-ulid', wfUlid()))->toThrow(InvalidArgumentException::class);
});

it('refuses a float amount', function () {
    expect(fn () => new WorkflowContext(wfUlid(), wfUlid(), amount: wfMixed(5.5)))->toThrow(TypeError::class);
});

it('refuses an amount with 5 decimal places', function () {
    expect(fn () => new WorkflowContext(wfUlid(), wfUlid(), amount: '1.23456'))->toThrow(InvalidArgumentException::class);

    // The control: four decimal places is accepted.
    expect((new WorkflowContext(wfUlid(), wfUlid(), amount: '1.2345'))->amount)->toBe('1.2345');
});

it('refuses a reserved field key', function () {
    expect(fn () => new WorkflowContext(wfUlid(), wfUlid(), fields: ['amount' => 1]))->toThrow(InvalidArgumentException::class);
});

it('refuses a float field value', function () {
    expect(fn () => new WorkflowContext(wfUlid(), wfUlid(), fields: ['grade' => wfMixed(1.5)]))->toThrow(InvalidArgumentException::class);
});

it('merges amount and branch_id into values()', function () {
    $branchId = wfUlid();
    $context = new WorkflowContext(wfUlid(), wfUlid(), branchId: $branchId, amount: 500, fields: ['category' => 'stationery']);

    expect($context->values())->toBe([
        'category' => 'stationery',
        'amount' => 500,
        'branch_id' => strtoupper($branchId),
    ]);
});
