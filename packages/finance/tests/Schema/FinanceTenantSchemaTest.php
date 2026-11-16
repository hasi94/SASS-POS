<?php

namespace Flexipos\Finance\Tests\Schema;

use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| The three finance tables, applied to a SQLite stand-in (T-032 plan step 2,
| the T-028 shape)
|--------------------------------------------------------------------------
|
| The schema halves of AC-3, AC-17, AC-31, AC-35 and AC-66.
|
| No lengths or precisions are asserted here: SQLite reports bare types.
| `FinanceWidthsTest` and `TenantDecimalPrecisionTest` (step 3) handle those
| statically. The generated marker's real-engine behaviour (a case-insensitive
| IN) is `[ENGINE]`, plan step 20, and is not claimed by this file.
*/

function financeSchemaConnection(): string
{
    return 'finance_schema_stand_in';
}

function financeApplyTenantTree(): void
{
    config()->set('database.connections.'.financeSchemaConnection(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge(financeSchemaConnection());

    expect(Artisan::call('migrate', [
        '--database' => financeSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    // The tree now ends in the seed migration (step 16): these tests are about
    // the tables' constraints, so they start from empty tables.
    $connection = DB::connection(financeSchemaConnection());
    $connection->table('fiscal_periods')->delete();
    $connection->table('fiscal_years')->delete();
    $connection->table('accounts')->update(['parent_id' => null]);
    $connection->table('accounts')->delete();
}

/**
 * @return list<string>
 */
function financeColumns(string $table): array
{
    $names = array_column(Schema::connection(financeSchemaConnection())->getColumns($table), 'name');
    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function financeNullableColumns(string $table): array
{
    $names = array_column(
        array_filter(Schema::connection(financeSchemaConnection())->getColumns($table), static fn (array $column): bool => $column['nullable']),
        'name'
    );
    sort($names);

    return $names;
}

function financeNow(): string
{
    return '2026-11-15 00:00:00.000';
}

/**
 * One raw `accounts` row. Raw inserts, because these are the engine-level
 * backstops: the handlers that sit above them arrive in later steps.
 */
function financeInsertAccount(string $id, string $code, string $controlType = 'none', ?string $deletedAt = null): void
{
    $now = financeNow();

    DB::connection(financeSchemaConnection())->table('accounts')->insert([
        'id' => str_pad($id, 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $deletedAt,
        'code' => $code, 'name' => 'Account '.$code, 'type' => 'asset', 'path' => str_pad($id, 26, 'x'),
        'depth' => 0, 'normal_balance' => 'debit', 'control_type' => $controlType,
    ]);
}

function financeInsertYear(string $id, string $name, string $start, string $end): void
{
    $now = financeNow();

    DB::connection(financeSchemaConnection())->table('fiscal_years')->insert([
        'id' => str_pad($id, 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'name' => $name, 'start_date' => $start, 'end_date' => $end,
    ]);
}

function financeInsertPeriod(string $id, string $yearId, int $sequence, string $start, string $end): void
{
    DB::connection(financeSchemaConnection())->table('fiscal_periods')->insert([
        'id' => str_pad($id, 26, 'x'), 'fiscal_year_id' => str_pad($yearId, 26, 'x'), 'sequence' => $sequence,
        'name' => 'P'.$sequence, 'start_date' => $start, 'end_date' => $end,
    ]);
}

it('creates accounts with exactly the documented columns', function () {
    financeApplyTenantTree();

    expect(financeColumns('accounts'))->toBe([
        'code', 'control_marker', 'control_type', 'created_at', 'created_by', 'currency', 'deleted_at',
        'depth', 'description', 'id', 'is_active', 'is_postable', 'is_system', 'name', 'normal_balance',
        'opening_balance', 'opening_balance_date', 'parent_id', 'path', 'sort_order', 'subtype', 'type',
        'updated_at', 'updated_by',
    ])->and(financeNullableColumns('accounts'))->toBe([
        'control_marker', 'created_by', 'currency', 'deleted_at', 'description', 'opening_balance',
        'opening_balance_date', 'parent_id', 'subtype', 'updated_by',
    ]);
});

it('creates fiscal_years with exactly the documented columns', function () {
    financeApplyTenantTree();

    expect(financeColumns('fiscal_years'))->toBe([
        'closed_at', 'closed_by', 'created_at', 'end_date', 'id', 'name', 'retained_earnings_entry_id',
        'start_date', 'status', 'updated_at',
    ])->and(financeNullableColumns('fiscal_years'))->toBe([
        'closed_at', 'closed_by', 'retained_earnings_entry_id',
    ]);
});

it('creates fiscal_periods with exactly the documented columns and no row timestamps (F-18)', function () {
    financeApplyTenantTree();

    expect(financeColumns('fiscal_periods'))->toBe([
        'closed_at', 'closed_by', 'end_date', 'fiscal_year_id', 'id', 'name', 'sequence', 'start_date', 'status',
    ])->and(financeNullableColumns('fiscal_periods'))->toBe([
        'closed_at', 'closed_by',
    ]);
});

it('holds accounts.code unique across live and soft-deleted rows (AC-3)', function () {
    financeApplyTenantTree();

    financeInsertAccount('a1', 'AB1');

    expect(fn () => financeInsertAccount('a2', 'AB1'))->toThrow(QueryException::class);

    // A soft-deleted account keeps its code reserved.
    financeInsertAccount('a3', 'AB2', 'none', financeNow());

    expect(fn () => financeInsertAccount('a4', 'AB2'))->toThrow(QueryException::class);
});

it('holds exactly one LIVE account per single control type, and lets every other shape coexist (AC-17)', function () {
    financeApplyTenantTree();
    $connection = DB::connection(financeSchemaConnection());

    financeInsertAccount('ar1', 'A100', 'ar');

    // A second live receivable refuses.
    expect(fn () => financeInsertAccount('ar2', 'A101', 'ar'))->toThrow(QueryException::class);

    // A soft-deleted holder does not block, and does not count against a live one.
    financeInsertAccount('ar3', 'A102', 'ar', financeNow());
    financeInsertAccount('ar4', 'A103', 'ar', financeNow());

    // The marker is the live holder only.
    expect($connection->table('accounts')->whereNotNull('control_marker')->pluck('control_marker')->all())->toBe(['ar']);

    // A repeatable control type, and `none`, coexist.
    financeInsertAccount('c1', 'C100', 'cash');
    financeInsertAccount('c2', 'C101', 'cash');
    financeInsertAccount('n1', 'N100');
    financeInsertAccount('n2', 'N101');

    expect($connection->table('accounts')->where('control_type', 'cash')->count())->toBe(2)
        ->and($connection->table('accounts')->where('control_type', 'none')->count())->toBe(2);

    // Every other single type is a one-holder type too, not just `ar`.
    foreach (ControlType::singles() as $index => $single) {
        if ($single === ControlType::Ar) {
            continue;
        }

        financeInsertAccount('s'.$index, 'S'.$index.'0', $single->value);

        expect(fn () => financeInsertAccount('t'.$index, 'S'.$index.'1', $single->value))
            ->toThrow(QueryException::class);
    }
});

it('keeps the control_marker list in the migration equal to ControlType::isSingle()', function () {
    $source = (string) file_get_contents(base_path((string) config('schema.tenant_migration_path')).'/2026_11_15_003201_create_accounts_table.php');

    expect(preg_match('/control_type IN \(([^)]*)\)/', $source, $matches))->toBe(1);

    preg_match_all("/'([a-z_]+)'/", $matches[1], $names);

    $fromMigration = $names[1];
    sort($fromMigration);

    $fromEnum = array_map(
        static fn (ControlType $type): string => $type->value,
        array_values(array_filter(ControlType::cases(), static fn (ControlType $type): bool => $type->isSingle()))
    );
    sort($fromEnum);

    expect($fromMigration)->toBe($fromEnum)->and($fromEnum)->toHaveCount(5);
});

it('holds fiscal_years.start_date and fiscal_years.name unique (AC-33, AC-34)', function () {
    financeApplyTenantTree();

    financeInsertYear('y1', 'FY2026', '2026-01-01', '2026-12-31');

    expect(fn () => financeInsertYear('y2', 'Other', '2026-01-01', '2026-12-31'))->toThrow(QueryException::class)
        ->and(fn () => financeInsertYear('y3', 'FY2026', '2027-01-01', '2027-12-31'))->toThrow(QueryException::class);

    financeInsertYear('y4', 'FY2027', '2027-01-01', '2027-12-31');

    expect(DB::connection(financeSchemaConnection())->table('fiscal_years')->count())->toBe(2);
});

it('holds fiscal_periods.start_date unique, which is the overlap backstop, and (fiscal_year_id, sequence) unique (AC-35)', function () {
    financeApplyTenantTree();

    financeInsertYear('y1', 'FY2026', '2026-01-01', '2026-12-31');
    financeInsertYear('y2', 'FY2026b', '2026-06-01', '2027-05-31');

    financeInsertPeriod('p1', 'y1', 1, '2026-01-01', '2026-01-31');

    // The same sequence in the same year.
    expect(fn () => financeInsertPeriod('p2', 'y1', 1, '2026-02-01', '2026-02-28'))->toThrow(QueryException::class);

    // The same start date in another year: two overlapping years always share one.
    expect(fn () => financeInsertPeriod('p3', 'y2', 1, '2026-01-01', '2026-01-31'))->toThrow(QueryException::class);

    // The same sequence number in a different year, on a different start date, is fine.
    financeInsertPeriod('p4', 'y2', 1, '2026-06-01', '2026-06-30');

    expect(DB::connection(financeSchemaConnection())->table('fiscal_periods')->count())->toBe(2);
});

it('holds exactly the six documented foreign keys', function () {
    financeApplyTenantTree();

    $keys = [];

    foreach (['accounts', 'fiscal_periods', 'fiscal_years'] as $table) {
        foreach (DB::connection(financeSchemaConnection())->select('pragma foreign_key_list("'.$table.'")') as $key) {
            $key = (array) $key;
            $keys[] = $table.'.'.$key['from'].' -> '.$key['table'].'.'.$key['to'];
        }
    }

    sort($keys);

    expect($keys)->toBe([
        'accounts.created_by -> employees.id',
        'accounts.parent_id -> accounts.id',
        'accounts.updated_by -> employees.id',
        'fiscal_periods.closed_by -> employees.id',
        'fiscal_periods.fiscal_year_id -> fiscal_years.id',
        'fiscal_years.closed_by -> employees.id',
    ]);
});

it('builds fiscal_years.retained_earnings_entry_id with no foreign key (F-14)', function () {
    financeApplyTenantTree();

    $from = array_map(
        static fn (object $key): string => (string) ((array) $key)['from'],
        DB::connection(financeSchemaConnection())->select('pragma foreign_key_list("fiscal_years")')
    );

    // The column exists (so the absence below is not an absent table), and it
    // is not a key.
    expect(financeColumns('fiscal_years'))->toContain('retained_earnings_entry_id')
        ->and($from)->toBe(['closed_by']);
});

it('cascades the delete of a fiscal year to its periods, and nothing else (AC-37)', function () {
    financeApplyTenantTree();
    $connection = DB::connection(financeSchemaConnection());

    financeInsertYear('y1', 'FY2026', '2026-01-01', '2026-12-31');
    financeInsertYear('y2', 'FY2027', '2027-01-01', '2027-12-31');
    financeInsertPeriod('p1', 'y1', 1, '2026-01-01', '2026-01-31');
    financeInsertPeriod('p2', 'y1', 2, '2026-02-01', '2026-02-28');
    financeInsertPeriod('p3', 'y2', 1, '2027-01-01', '2027-01-31');

    $connection->table('fiscal_years')->where('id', str_pad('y1', 26, 'x'))->delete();

    expect($connection->table('fiscal_periods')->pluck('id')->all())->toBe([str_pad('p3', 26, 'x')]);
});

it('runs cleanly twice as a no-op, and no finance table file declares a down()', function () {
    financeApplyTenantTree();

    $before = Schema::connection(financeSchemaConnection())->getTableListing();

    expect(Artisan::call('migrate', [
        '--database' => financeSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    expect(Schema::connection(financeSchemaConnection())->getTableListing())->toBe($before);

    $files = glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_15_00320[123]_*.php');

    expect($files)->toHaveCount(3);

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('function down(');
    }
});

it('reports no SourceRules violation across the three finance table migrations', function () {
    $violations = [];
    $files = glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_15_00320[123]_*.php');

    // Three files scanned, so an empty result is evidence and not an empty glob.
    expect($files)->toHaveCount(3);

    foreach ($files as $file) {
        $violations = array_merge($violations, SourceRules::inspect((string) file_get_contents($file), MigrationTree::Tenant));
    }

    expect($violations)->toBe([]);
});
