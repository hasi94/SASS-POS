<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Seed;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Finance\Accounts\AccountInput;
use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Accounts\AccountRules;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\FiscalCalendar;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 16 - the seed migration (AC-26 to AC-30, D-6, D-8, D-27)
|--------------------------------------------------------------------------
|
| The fixture template already holds the shipped seed, so each test empties
| the chart and the calendar first (`financeEnterTenant` does) and runs the
| migration's own `up()` on the tenant connection. Exactly one test keeps the
| template as shipped, to prove a freshly provisioned tenant carries it.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/finance-fixture.php';

const SEED_MIGRATION = '2026_11_15_003204_seed_chart_of_accounts_and_first_fiscal_year';

beforeEach(function () {
    AccessTenantFixture::boot();
    financeEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-06-15 09:30:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function seedMigrationPath(): string
{
    return dirname(__DIR__, 4).'/modules/host/database/migrations-tenant/'.SEED_MIGRATION.'.php';
}

function seedMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require seedMigrationPath();

    return $migration;
}

/**
 * @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: string, 5: bool, 6: ?string}>
 */
function seedRows(): array
{
    return constant(seedMigration()::class.'::ROWS');
}

/**
 * @return list<string>
 */
function seedSingleControl(): array
{
    return constant(seedMigration()::class.'::SINGLE_CONTROL');
}

function runSeed(): void
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection(AccessTenantFixture::connectionName());

    try {
        $migration = seedMigration();

        // `up()` is declared by each migration, not by the base class.
        if (! method_exists($migration, 'up')) {
            throw new LogicException('The seed migration has no up().');
        }

        $migration->up();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/**
 * The chart as the plan (section 4) and the spec (AC-26, D-23) state it,
 * written out here independently of the migration's own list:
 * `code => [name, type, subtype, control, header, parent code]`.
 *
 * @return array<int|string, array{0: string, 1: string, 2: ?string, 3: string, 4: bool, 5: ?string}>
 */
function expectedChart(): array
{
    return [
        '1000' => ['Assets', 'asset', null, 'none', true, null],
        '1100' => ['Cash', 'asset', 'cash', 'cash', false, '1000'],
        '1200' => ['Bank', 'asset', 'bank', 'bank', false, '1000'],
        '1300' => ['Inventory', 'asset', 'inventory', 'inventory', false, '1000'],
        '1400' => ['Accounts Receivable', 'asset', 'receivable', 'ar', false, '1000'],
        '2000' => ['Liabilities', 'liability', null, 'none', true, null],
        '2100' => ['Supplier Payables', 'liability', 'payable', 'ap', false, '2000'],
        '2200' => ['Loans', 'liability', 'long_term_liability', 'none', false, '2000'],
        '2300' => ['Tax Payable', 'liability', 'tax', 'tax_payable', false, '2000'],
        '2400' => ['Goods Received Not Invoiced', 'liability', 'current_liability', 'grni', false, '2000'],
        '2500' => ['Payroll Payable', 'liability', 'current_liability', 'payroll_payable', false, '2000'],
        '3000' => ['Equity', 'equity', null, 'none', true, null],
        '3100' => ['Retained Earnings', 'equity', 'retained_earnings', 'retained_earnings', false, '3000'],
        '4000' => ['Revenue', 'revenue', null, 'none', true, null],
        '4100' => ['Sales', 'revenue', 'sales', 'none', false, '4000'],
        '5000' => ['Cost of Goods Sold', 'expense', 'cogs', 'none', false, null],
        '6000' => ['Expenses', 'expense', null, 'none', true, null],
        '6100' => ['Rent', 'expense', 'operating_expense', 'none', false, '6000'],
        '6200' => ['Salary', 'expense', 'payroll_expense', 'none', false, '6000'],
        '6300' => ['Electricity', 'expense', 'operating_expense', 'none', false, '6000'],
    ];
}

/**
 * Every seed row pushed through the validators the API uses (AC-29). Returns
 * one message per violation, so an empty list means the data is valid.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: ?string, 4: string, 5: bool, 6: ?string}>  $rows
 * @return list<string>
 */
function seedRowViolations(array $rows): array
{
    $accounts = app(AccountRows::class);
    $violations = [];
    $ids = [];

    FinanceSeed::connection()->table('accounts')->update(['parent_id' => null]);
    FinanceSeed::connection()->table('accounts')->delete();

    foreach ($rows as [$code, $name, $type, $subtype, $control, $header, $parentCode]) {
        try {
            $draft = AccountInput::forCreate(array_filter([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'subtype' => $subtype,
                'control_type' => $control,
                'is_postable' => ! $header,
                'parent_id' => $parentCode === null ? null : ($ids[$parentCode] ?? null),
            ], static fn (mixed $v): bool => $v !== null));

            $parent = null;

            if ($parentCode !== null) {
                $parentRow = FinanceSeed::connection()->table('accounts')->where('id', $ids[$parentCode] ?? '')->first();
                $parent = $parentRow === null ? null : $accounts->state($parentRow);
            }

            if ($parentCode !== null) {
                AccountRules::parent($parent, $draft->type);
            }

            $ids[$code] = FinanceSeed::account($code, [
                'name' => $name, 'type' => $type, 'subtype' => $subtype, 'control_type' => $control,
                'is_postable' => ! $header, 'parent_id' => $parentCode === null ? null : ($ids[$parentCode] ?? null),
                'normal_balance' => $draft->normalBalance?->value,
            ]);
        } catch (FinanceRefused $refused) {
            $violations[] = "{$code}: {$refused->reason->value}";
        }
    }

    return $violations;
}

it('writes exactly the twenty accounts of the plan, with parents, types, subtypes and controls (AC-26)', function () {
    runSeed();

    $rows = FinanceSeed::connection()->table('accounts')->orderBy('code')->get();
    $byId = $rows->keyBy('id');
    $actual = [];

    foreach ($rows as $row) {
        $actual[$row->code] = [
            $row->name, $row->type, $row->subtype, $row->control_type, ! (bool) $row->is_postable,
            $row->parent_id === null ? null : $byId[$row->parent_id]->code,
        ];
    }

    expect($actual)->toBe(expectedChart());
});

it('makes the five roots headers, every other row postable, system iff controlled, and one holder per single control (AC-27)', function () {
    runSeed();

    $rows = FinanceSeed::connection()->table('accounts')->get();

    foreach ($rows as $row) {
        $isHeader = in_array($row->code, ['1000', '2000', '3000', '4000', '6000'], true);

        expect((bool) $row->is_postable)->toBe(! $isHeader, $row->code)
            ->and((bool) $row->is_system)->toBe($row->control_type !== 'none', $row->code)
            ->and($row->deleted_at)->toBeNull()
            ->and((bool) $row->is_active)->toBeTrue()
            ->and($row->created_by)->toBeNull();
    }

    expect($rows->where('is_system', 1)->count())->toBe(9);

    foreach (['ar', 'ap', 'grni', 'payroll_payable', 'retained_earnings'] as $single) {
        expect($rows->where('control_type', $single)->count())->toBe(1, $single);
    }
});

it('gives every row the path, depth and normal balance the API would (AC-26, AC-11, AC-6)', function () {
    runSeed();

    $rows = FinanceSeed::connection()->table('accounts')->get()->keyBy('id');

    foreach ($rows as $row) {
        $parent = $row->parent_id === null ? null : $rows[$row->parent_id];

        expect($row->path)->toBe($parent === null ? $row->id : $parent->path.'/'.$row->id)
            ->and($row->depth)->toBe($parent === null ? 0 : $parent->depth + 1)
            ->and($row->normal_balance)->toBe(in_array($row->type, ['asset', 'expense'], true) ? 'debit' : 'credit')
            ->and(preg_match('/\A[0-9A-Z]{26}\z/', $row->id))->toBe(1)
            ->and($row->opening_balance)->toBeNull();
    }
});

it('runs twice with no duplicate and no change, and writes no audit row (AC-28, D-27)', function () {
    runSeed();
    $first = FinanceSeed::snapshot();
    $calendar = FinanceSeed::calendarSnapshot();

    runSeed();

    expect(FinanceSeed::snapshot())->toBe($first)
        ->and(FinanceSeed::calendarSnapshot())->toBe($calendar)
        ->and(FinanceSeed::connection()->table('accounts')->count())->toBe(20)
        ->and(FinanceSeed::audit())->toBe([]);
});

it('keeps a seeded account the tenant renamed (AC-28)', function () {
    runSeed();

    FinanceSeed::connection()->table('accounts')->where('code', '4100')->update(['name' => 'Shop Sales']);

    runSeed();

    expect(FinanceSeed::connection()->table('accounts')->where('code', '4100')->value('name'))->toBe('Shop Sales')
        ->and(FinanceSeed::connection()->table('accounts')->count())->toBe(20);
});

it('leaves a tenant\'s own account on a seed code alone, skipping its subtree when it cannot be a parent (AC-28)', function () {
    // 1100 is a header of the wrong type (a leaf in the seed); 2000 is a postable
    // asset where the seed has a liability header, so 2100 to 2500 have no parent.
    $wrongHeader = FinanceSeed::header('1100', ['type' => 'liability', 'normal_balance' => 'credit', 'name' => 'Mine']);
    $wrongParent = FinanceSeed::account('2000', ['name' => 'Also mine']);

    runSeed();

    $codes = FinanceSeed::connection()->table('accounts')->orderBy('code')->pluck('code')->all();

    expect(FinanceSeed::row($wrongHeader))->toMatchArray(['name' => 'Mine', 'type' => 'liability', 'is_postable' => 0])
        ->and(FinanceSeed::row($wrongParent))->toMatchArray(['name' => 'Also mine', 'type' => 'asset', 'is_postable' => 1])
        ->and($codes)->toBe(['1000', '1100', '1200', '1300', '1400', '2000', '3000', '3100', '4000', '4100', '5000', '6000', '6100', '6200', '6300']);
});

it('hangs a seed child under a tenant\'s own live header of the same type (AC-28)', function () {
    $mine = FinanceSeed::header('1000', ['name' => 'My assets']);

    runSeed();

    $cash = FinanceSeed::connection()->table('accounts')->where('code', '1100')->first();

    expect($cash->parent_id)->toBe($mine)
        ->and($cash->path)->toBe(FinanceSeed::row($mine)['path'].'/'.$cash->id)
        ->and($cash->depth)->toBe(1)
        ->and(FinanceSeed::row($mine)['name'])->toBe('My assets');
});

it('resumes a partial run to the full set without touching what was there (AC-28)', function () {
    runSeed();

    $order = ['1000', '1100', '1200', '1300', '1400', '2000', '2100'];
    $kept = FinanceSeed::connection()->table('accounts')->whereIn('code', $order)->get()->keyBy('code');

    // Cut the chart back to its first seven rows, children first.
    foreach (array_reverse(array_map(strval(...), array_keys(expectedChart()))) as $code) {
        if (! in_array($code, $order, true)) {
            FinanceSeed::connection()->table('accounts')->where('code', $code)->delete();
        }
    }

    expect(FinanceSeed::connection()->table('accounts')->count())->toBe(7);

    runSeed();

    expect(FinanceSeed::connection()->table('accounts')->count())->toBe(20);

    foreach ($kept as $code => $row) {
        expect((array) FinanceSeed::connection()->table('accounts')->where('code', $code)->first())->toBe((array) $row);
    }

    expect(FinanceSeed::connection()->table('accounts')->where('code', '2200')->value('parent_id'))->toBe($kept['2000']->id);
});

it('treats a soft-deleted holder of a code as holding it, and of a control type as not holding it (AC-28, AC-17)', function () {
    $deletedCode = FinanceSeed::account('1100', ['deleted_at' => FinanceSeed::NOW, 'is_active' => false]);
    $deletedAp = FinanceSeed::account('OLD-AP', ['deleted_at' => FinanceSeed::NOW, 'type' => 'liability', 'subtype' => 'payable', 'normal_balance' => 'credit', 'control_type' => 'ap']);
    $liveAr = FinanceSeed::account('MY-AR', ['subtype' => 'receivable', 'control_type' => 'ar']);

    runSeed();

    $rows = FinanceSeed::connection()->table('accounts');

    expect(FinanceSeed::row($deletedCode)['deleted_at'])->not->toBeNull()
        ->and((clone $rows)->where('code', '1100')->count())->toBe(1)
        // The deleted AP holder does not block the seeded 2100.
        ->and((clone $rows)->where('code', '2100')->count())->toBe(1)
        ->and(FinanceSeed::row($deletedAp)['deleted_at'])->not->toBeNull()
        // A live AR holder does: 1400 is skipped, and there is still exactly one.
        ->and((clone $rows)->where('code', '1400')->count())->toBe(0)
        ->and((clone $rows)->where('control_type', 'ar')->whereNull('deleted_at')->count())->toBe(1)
        ->and(FinanceSeed::row($liveAr)['code'])->toBe('MY-AR');
});

it('pushes every seed row through the API validators and finds no violation (AC-29)', function () {
    expect(seedRowViolations(seedRows()))->toBe([]);
});

it('reports a seed row the validators refuse: 2100 typed as an asset (AC-29 red-first control)', function () {
    $rows = array_map(
        static fn (array $row): array => $row[0] === '2100' ? [$row[0], $row[1], 'asset', $row[3], $row[4], $row[5], $row[6]] : $row,
        seedRows(),
    );

    // The row is wrong on its own: `payable` is not an asset subtype.
    expect(seedRowViolations($rows))->toBe(['2100: subtype_mismatch']);
});

it('holds the migration\'s single-holder list equal to the contract\'s (AC-17, AC-29)', function () {
    $contract = array_map(
        static fn (ControlType $c): string => $c->value,
        array_values(array_filter(ControlType::cases(), static fn (ControlType $c): bool => $c->isSingle())),
    );

    expect(seedSingleControl())->toEqualCanonicalizing($contract);
});

it('creates the current year with twelve open periods, equal to the calendar the API uses (AC-30)', function () {
    runSeed();

    $year = FinanceSeed::connection()->table('fiscal_years')->first();
    $periods = FinanceSeed::connection()->table('fiscal_periods')->orderBy('sequence')->get();

    expect(FinanceSeed::connection()->table('fiscal_years')->count())->toBe(1)
        ->and($year)->toMatchArray([
            'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => FiscalCalendar::yearEnd('2026-01-01'),
            'status' => 'open', 'closed_at' => null, 'closed_by' => null, 'retained_earnings_entry_id' => null,
        ])
        ->and($periods)->toHaveCount(12);

    foreach (FiscalCalendar::periodsFor('2026-01-01') as $index => $expected) {
        $period = $periods[$index];

        expect([$period->fiscal_year_id, $period->sequence, $period->name, $period->start_date, $period->end_date, $period->status, $period->closed_at, $period->closed_by])
            ->toBe([$year->id, $expected['sequence'], 'FY2026 '.$expected['name'], $expected['start'], $expected['end'], 'open', null, null]);
    }
});

it('names the year after the UTC calendar year of the runner clock (AC-30)', function (string $now, string $name) {
    app()->instance(RunnerClock::class, RunnerClock::fixedAt($now));

    runSeed();

    expect(FinanceSeed::connection()->table('fiscal_years')->value('name'))->toBe($name);
})->with([
    'mid year' => ['2031-06-15 09:30:00.000', 'FY2031'],
    'last second' => ['2031-12-31 23:59:59.999', 'FY2031'],
    'first second' => ['2032-01-01 00:00:00.000', 'FY2032'],
]);

it('adds no year when one already exists, and leaves it as it is (AC-30)', function () {
    $existing = FinanceSeed::fiscalYear('2030-04-01', 'Mine');
    $before = FinanceSeed::calendarSnapshot();

    runSeed();

    expect(FinanceSeed::calendarSnapshot())->toBe($before)
        ->and(FinanceSeed::yearRow($existing['id'])['name'])->toBe('Mine')
        ->and(FinanceSeed::connection()->table('accounts')->count())->toBe(20);
});

it('is already in a freshly provisioned tenant: the shipped template carries the chart and the year (AC-26, AC-30)', function () {
    AccessTenantFixture::forget();
    AccessTenantFixture::boot();
    financeEnterTenant('tenant-a', clearSeed: false);

    expect(FinanceSeed::connection()->table('accounts')->count())->toBe(20)
        ->and(FinanceSeed::connection()->table('fiscal_years')->count())->toBe(1)
        ->and(FinanceSeed::connection()->table('fiscal_periods')->count())->toBe(12)
        ->and(FinanceSeed::connection()->table('fiscal_years')->value('name'))->toBe('FY'.gmdate('Y'))
        ->and(FinanceSeed::audit())->toBe([]);
});

it('is catalogued as a backfill and not destructive (AC-54)', function () {
    $catalogue = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/modules/host/database/catalogue/tenant_schema_versions.json'), true);

    $entries = array_values(array_filter($catalogue, static fn (array $entry): bool => $entry['migration'] === SEED_MIGRATION));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['phase'])->toBe('backfill')
        ->and($entries[0]['is_destructive'])->toBeFalse()
        ->and($entries[0]['requires_offline'])->toBeFalse();
});
