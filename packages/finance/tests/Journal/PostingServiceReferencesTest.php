<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Journal;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\AccountRefusal;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step C4 - references (AC-15a, D-26)
|--------------------------------------------------------------------------
|
| A well-formed id that names no live row is refused `unknown_reference` with
| its field path, after the account check and before the number, and a raw
| foreign-key error never surfaces. `department_id` and `supplier_id` have no
| table yet and are not checked (D-16, F-13).
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/journal-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    financeEnterTenant();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 10:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * A live row of the target, and the same kind soft-deleted.
 *
 * @return array{live: string, dead: string}
 */
function refTarget(string $table): array
{
    $deleted = '2026-01-01 00:00:00.000';

    return match ($table) {
        'branches' => ['live' => AccessSeed::branch(), 'dead' => (function () use ($deleted): string {
            $id = AccessSeed::branch();
            FinanceSeed::connection()->table('branches')->where('id', $id)->update(['deleted_at' => $deleted]);

            return $id;
        })()],
        'employees' => ['live' => AccessSeed::employee('ref-live'), 'dead' => AccessSeed::employee('ref-dead', ['deleted_at' => $deleted])],
        'locations', 'customers', 'products' => ['live' => jrnForeignRow($table), 'dead' => jrnForeignRow($table, ['deleted_at' => $deleted])],
        default => throw new \InvalidArgumentException($table),
    };
}

/** dimension => table */
const REF_TARGETS = [
    'location_id' => 'locations',
    'employee_id' => 'employees',
    'customer_id' => 'customers',
    'product_id' => 'products',
];

it('refuses an unknown or soft-deleted id on every checked reference, with the path and line index, and no QueryException (AC-15a, M19)', function (string $where, string $state) {
    $world = jrnWorld();

    $table = $where === 'entry branch' || $where === 'line branch' ? 'branches' : REF_TARGETS[$where];
    $id = $state === 'unknown' ? (string) Str::ulid() : refTarget($table)['dead'];

    $body = jrnBody($world, ['lines' => [
        ['account_id' => $world['cash'], 'debit' => '5'],
        array_merge(['account_id' => $world['sales'], 'credit' => '5'], $where === 'line branch' ? ['branch_id' => $id] : ($where === 'entry branch' ? [] : [$where => $id])),
    ]]);

    if ($where === 'entry branch') {
        $body['branch_id'] = $id;
    }

    $before = jrnState();

    // A raw foreign-key error would surface here as a QueryException and fail the test.
    $refusal = jrnPostRefusal($body, $world['actor']);

    $field = $where === 'entry branch' ? 'branch_id' : 'lines.1.'.($where === 'line branch' ? 'branch_id' : $where);

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe(PostingRefusal::UnknownReference)
        ->and($refusal->field)->toBe($field)
        ->and($refusal->context)->toBe($where === 'entry branch' ? [] : ['line_index' => 1])
        ->and(jrnState())->toBe($before);
})->with(function () {
    foreach (['entry branch', 'line branch', 'location_id', 'employee_id', 'customer_id', 'product_id'] as $where) {
        foreach (['unknown', 'soft-deleted'] as $state) {
            yield "{$where} / {$state}" => [$where, $state];
        }
    }
});

it('accepts live references of every checked kind, stores them, and does not check department_id or supplier_id (AC-15a, D-16)', function () {
    $world = jrnWorld();
    $branch = AccessSeed::branch();
    $location = jrnForeignRow('locations');
    $customer = jrnForeignRow('customers');
    $product = jrnForeignRow('products');
    $employee = AccessSeed::employee('ref-live');
    $department = (string) Str::ulid();
    $supplier = (string) Str::ulid();

    $entry = jrnPost(jrnBody($world, [
        'branch_id' => $branch,
        'lines' => [
            [
                'account_id' => $world['cash'], 'debit' => '5',
                'branch_id' => $branch, 'location_id' => $location, 'employee_id' => $employee,
                'customer_id' => $customer, 'product_id' => $product, 'department_id' => $department, 'supplier_id' => $supplier,
            ],
            ['account_id' => $world['sales'], 'credit' => '5'],
        ],
    ]));

    $line = jrnLines($entry->id)[0];

    expect([$line['branch_id'], $line['location_id'], $line['employee_id'], $line['customer_id'], $line['product_id'], $line['department_id'], $line['supplier_id']])
        ->toBe([$branch, $location, $employee, $customer, $product, $department, $supplier])
        ->and(jrnEntry($entry->id)['branch_id'])->toBe($branch);
});

it('runs after the account check and before the number: both wrong gives the account code, a bad reference consumes no number (AC-15a)', function () {
    $world = jrnWorld();
    $header = FinanceSeed::header('1900');

    $refusal = jrnPostRefusal(jrnBody($world, [
        'branch_id' => (string) Str::ulid(),
        'lines' => [['account_id' => $header, 'debit' => '5'], ['account_id' => $world['sales'], 'credit' => '5']],
    ]));

    expect($refusal->reason)->toBe(AccountRefusal::AccountNotPostable);

    $before = jrnState();
    $refusal = jrnPostRefusal(jrnBody($world, ['branch_id' => (string) Str::ulid()]));

    expect($refusal->reason)->toBe(PostingRefusal::UnknownReference)
        ->and(jrnState()['next'])->toBe($before['next']);

    expect(jrnPost(jrnBody($world))->number)->toBe('JE-000001');
});

it('reports the first missing reference in request order: the entry branch, then lines ascending, then dimension order (AC-15a)', function () {
    $world = jrnWorld();
    $liveBranch = AccessSeed::branch();
    $ghost = static fn (): string => (string) Str::ulid();

    // Entry branch before any line.
    $refusal = jrnPostRefusal(jrnBody($world, ['branch_id' => $ghost(), 'lines' => [
        ['account_id' => $world['cash'], 'debit' => '5', 'location_id' => $ghost()],
        ['account_id' => $world['sales'], 'credit' => '5'],
    ]]));

    expect($refusal->field)->toBe('branch_id');

    // Line 0 before line 1.
    $refusal = jrnPostRefusal(jrnBody($world, ['branch_id' => $liveBranch, 'lines' => [
        ['account_id' => $world['cash'], 'debit' => '5', 'product_id' => $ghost()],
        ['account_id' => $world['sales'], 'credit' => '5', 'branch_id' => $ghost()],
    ]]));

    expect($refusal->field)->toBe('lines.0.product_id');

    // Inside one line: location before employee before customer before product, whatever the key order given.
    $refusal = jrnPostRefusal(jrnBody($world, ['lines' => [
        ['account_id' => $world['cash'], 'debit' => '5', 'product_id' => $ghost(), 'customer_id' => $ghost(), 'employee_id' => $ghost(), 'location_id' => $ghost()],
        ['account_id' => $world['sales'], 'credit' => '5'],
    ]]));

    expect($refusal->field)->toBe('lines.0.location_id');
});

it('reads each target table once, however many lines name it, and with a deleted_at filter (AC-15a)', function () {
    $world = jrnWorld();
    $branch = AccessSeed::branch();
    $location = jrnForeignRow('locations');

    [$statements, $thrown] = jrnStatements(fn () => jrnPost(jrnBody($world, [
        'branch_id' => $branch,
        'lines' => [
            ['account_id' => $world['cash'], 'debit' => '1', 'branch_id' => $branch, 'location_id' => $location],
            ['account_id' => $world['cash'], 'debit' => '1', 'branch_id' => $branch, 'location_id' => $location],
            ['account_id' => $world['sales'], 'credit' => '2', 'branch_id' => $branch],
        ],
    ])));

    $branchReads = array_values(array_filter(jrnStatementsOn($statements, 'branches'), static fn (string $sql): bool => str_starts_with($sql, 'select')));
    $locationReads = array_values(array_filter(jrnStatementsOn($statements, 'locations'), static fn (string $sql): bool => str_starts_with($sql, 'select')));

    expect($thrown)->toBeNull()
        ->and($branchReads)->toHaveCount(1)
        ->and($locationReads)->toHaveCount(1)
        ->and($branchReads[0])->toContain('"deleted_at" is null')
        ->and(jrnStatementsOn($statements, 'customers'))->toBe([])
        ->and(jrnStatementsOn($statements, 'products'))->toBe([]);
});
