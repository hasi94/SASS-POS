<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| T-014 step 4 - the five organization tables (AC-1, AC-2 in-table half)
|--------------------------------------------------------------------------
|
| **Shape is asserted in both directions and the check is shown to bite.**
| AC-1 lists the columns of every table exactly and forbids the ones the
| design printed and the spec dropped, so the comparison is a set difference
| both ways (an extra column and a missing one are different defects), and the
| controls run the same function against a scratch table with one column too
| many and one with one too few. A comparison that could not be shown to
| report either would also report "no difference" over a table it never read.
|
| **AC-2 is about behaviour, not the declaration.** The unique keys are tried:
| a duplicate is refused and the control beside it - a distinct code, the same
| terminal code under another branch, a branch-less location - is accepted, so
| the refusal is attributable to the key and not to a stand-in that refuses
| everything. Foreign keys are only real because the stand-in connection turns
| enforcement on; SQLite parses a REFERENCES clause and ignores it otherwise.
|
| The keys the later contract migration adds (the four branch columns of the
| older tables, `default_location_id`, `current_device_id` and the three
| employee-audit columns) are BranchForeignKeysMigrationTest's. This file
| asserts only what the five table files themselves carry.
|
| No engine: a private in-memory SQLite connection this file configures, with
| the driver asserted before anything is migrated.
*/

const ORG_SHAPE_CONNECTION = 'org_shape_stand_in';

function orgShapeApply(): void
{
    config()->set('database.connections.'.ORG_SHAPE_CONNECTION, [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    app('db')->purge(ORG_SHAPE_CONNECTION);

    expect(config('database.connections.'.ORG_SHAPE_CONNECTION.'.driver'))->toBe('sqlite');

    expect(Artisan::call('migrate', [
        '--database' => ORG_SHAPE_CONNECTION,
        '--path' => tenantMigrationPath(),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * The live columns of one table, sorted.
 *
 * @return list<string>
 */
function orgShapeColumns(string $table): array
{
    $names = array_map(
        static fn (array $column): string => (string) $column['name'],
        Schema::connection(ORG_SHAPE_CONNECTION)->getColumns($table)
    );

    sort($names);

    return $names;
}

/**
 * The comparison AC-1 describes, as a function so its controls can run it
 * against a table that is wrong.
 *
 * @param  list<string>  $expected
 * @param  list<string>  $actual
 * @return array{extra: list<string>, missing: list<string>}
 */
function orgShapeDiff(array $expected, array $actual): array
{
    return [
        'extra' => array_values(array_diff($actual, $expected)),
        'missing' => array_values(array_diff($expected, $actual)),
    ];
}

/**
 * The columns "Data this task stores" lists, per table, as the spec words them.
 *
 * @return array<string, list<string>>
 */
function orgShapeSpecColumns(): array
{
    $lists = [
        'branches' => [
            'id', 'code', 'name', 'address', 'phone', 'email', 'timezone', 'currency', 'tax_registration_no',
            'business_type', 'opening_date', 'default_location_id', 'is_active', 'sort_order',
            'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by',
        ],
        'locations' => [
            'id', 'branch_id', 'code', 'name', 'type', 'address', 'manager_employee_id', 'allow_negative_stock',
            'is_sellable_from', 'is_purchasable_to', 'is_active', 'sort_order',
            'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by',
        ],
        'terminals' => [
            'id', 'branch_id', 'location_id', 'code', 'name', 'status', 'activation_code_hash',
            'activation_expires_at', 'current_device_id', 'last_seen_at', 'created_at', 'updated_at', 'deleted_at',
        ],
        'devices' => [
            'id', 'terminal_id', 'fingerprint', 'platform', 'browser', 'os', 'screen', 'registered_at',
            'last_seen_at', 'revoked_at', 'revoked_by', 'created_at', 'updated_at',
        ],
        'terminal_credentials' => [
            'terminal_id', 'device_fingerprint', 'status', 'activated_at', 'activated_by', 'revoked_at', 'revoked_by',
        ],
    ];

    foreach ($lists as &$list) {
        sort($list);
    }

    return $lists;
}

it('holds exactly the columns the spec lists in each of the five tables, and the check can tell otherwise (AC-1)', function () {
    orgShapeApply();

    $domain = [];

    foreach (orgShapeSpecColumns() as $table => $expected) {
        $domain[] = $table.':'.count($expected);

        expect(orgShapeDiff($expected, orgShapeColumns($table)))->toBe(['extra' => [], 'missing' => []], $table);
    }

    // The domain, printed: five tables read, none empty.
    expect($domain)->toBe(['branches:19', 'locations:17', 'terminals:13', 'devices:13', 'terminal_credentials:7']);

    // Control 1: a table with ONE EXTRA column is reported as extra.
    $schema = Schema::connection(ORG_SHAPE_CONNECTION);

    $schema->table('devices', static function ($table): void {
        $table->string('qa_extra')->nullable();
    });

    expect(orgShapeDiff(orgShapeSpecColumns()['devices'], orgShapeColumns('devices')))
        ->toBe(['extra' => ['qa_extra'], 'missing' => []]);

    $schema->table('devices', static function ($table): void {
        $table->dropColumn('qa_extra');
    });

    // Control 2: a table with ONE MISSING column is reported as missing.
    $schema->table('devices', static function ($table): void {
        $table->dropColumn('screen');
    });

    expect(orgShapeDiff(orgShapeSpecColumns()['devices'], orgShapeColumns('devices')))
        ->toBe(['extra' => [], 'missing' => ['screen']]);
});

/**
 * The columns of the five tables that the spec forbids, as `table.column`.
 *
 * @return list<string>
 */
function orgShapeForbiddenFound(): array
{
    $forbidden = [
        'tenant_id', 'settings', 'invoice_prefix', 'default_price_list_id', 'last_sync_at', 'offline_since',
        'app_version', 'agent_version', 'secret_hash', 'rotated_at', 'cost_centre_account_id',
        'custom_fields', 'activation_code',
    ];

    $found = [];

    foreach (array_keys(orgShapeSpecColumns()) as $table) {
        foreach (orgShapeColumns($table) as $column) {
            if (in_array($column, $forbidden, true) || str_starts_with($column, 'cf_')) {
                $found[] = $table.'.'.$column;
            }
        }
    }

    return $found;
}

it('creates no tenant_id column and none of the columns the design printed and the spec dropped (AC-1, D-10, D-24)', function () {
    orgShapeApply();

    // `activation_code_hash` is the column D-17 created in place of the
    // design's `activation_code`, and the exact-name match is what lets one
    // be present and the other forbidden.
    expect(orgShapeForbiddenFound())->toBe([])
        ->and(orgShapeColumns('terminals'))->toContain('activation_code_hash')
        ->and(orgShapeColumns('terminals'))->not->toContain('activation_code');

    // Liveness: the same scan DOES report a forbidden column once one exists,
    // so "found nothing" above is not an unread table.
    Schema::connection(ORG_SHAPE_CONNECTION)->table('terminals', static function ($table): void {
        $table->string('invoice_prefix')->nullable();
    });

    expect(orgShapeForbiddenFound())->toBe(['terminals.invoice_prefix']);
});

/*
|--------------------------------------------------------------------------
| AC-2 - the keys that the table files themselves carry
|--------------------------------------------------------------------------
*/

const ORG_SHAPE_NOW = '2026-10-22 09:00:00.000';

function orgShapeId(string $stem): string
{
    return strtoupper(str_pad(substr(preg_replace('/[^A-Za-z0-9]/', '', $stem) ?? '', 0, 26), 26, '0'));
}

function orgShapeBranch(string $stem, string $code, bool $deleted = false): string
{
    DB::connection(ORG_SHAPE_CONNECTION)->table('branches')->insert([
        'id' => orgShapeId($stem), 'code' => $code, 'name' => $stem, 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
        'created_at' => ORG_SHAPE_NOW, 'updated_at' => ORG_SHAPE_NOW, 'deleted_at' => $deleted ? ORG_SHAPE_NOW : null,
    ]);

    return orgShapeId($stem);
}

function orgShapeLocation(string $stem, string $code, ?string $branchId): string
{
    DB::connection(ORG_SHAPE_CONNECTION)->table('locations')->insert([
        'id' => orgShapeId($stem), 'branch_id' => $branchId, 'code' => $code, 'name' => $stem, 'type' => 'warehouse',
        'created_at' => ORG_SHAPE_NOW, 'updated_at' => ORG_SHAPE_NOW,
    ]);

    return orgShapeId($stem);
}

function orgShapeTerminal(string $stem, string $branchId, string $locationId, string $code): string
{
    DB::connection(ORG_SHAPE_CONNECTION)->table('terminals')->insert([
        'id' => orgShapeId($stem), 'branch_id' => $branchId, 'location_id' => $locationId, 'code' => $code,
        'name' => $stem, 'status' => 'pending', 'created_at' => ORG_SHAPE_NOW, 'updated_at' => ORG_SHAPE_NOW,
    ]);

    return orgShapeId($stem);
}

it('holds a branch code and a location code once each, forever, and accepts a distinct one (AC-2)', function () {
    orgShapeApply();

    $first = orgShapeBranch('branchA', 'MAIN');

    expect(fn () => orgShapeBranch('branchB', 'MAIN'))->toThrow(QueryException::class);

    // The control: the same insert with a distinct code is accepted.
    orgShapeBranch('branchC', 'MAIN2');

    // A soft-deleted row (fixture only; nothing in T-014 deletes) still holds
    // its code, so the unique key counts it.
    orgShapeBranch('branchD', 'OLD', deleted: true);

    expect(fn () => orgShapeBranch('branchE', 'OLD'))->toThrow(QueryException::class);

    orgShapeLocation('locA', 'WH1', $first);

    expect(fn () => orgShapeLocation('locB', 'WH1', null))->toThrow(QueryException::class);

    orgShapeLocation('locC', 'WH2', null);

    DB::connection(ORG_SHAPE_CONNECTION)->table('locations')->where('id', orgShapeId('locC'))->update(['deleted_at' => ORG_SHAPE_NOW]);

    expect(fn () => orgShapeLocation('locD', 'WH2', null))->toThrow(QueryException::class)
        ->and(DB::connection(ORG_SHAPE_CONNECTION)->table('branches')->count())->toBe(3);
});

it('holds a terminal code once per branch, and the same code under another branch is accepted (AC-2)', function () {
    orgShapeApply();

    $a = orgShapeBranch('branchA', 'A');
    $b = orgShapeBranch('branchB', 'B');
    $storeA = orgShapeLocation('storeA', 'SA', $a);
    $storeB = orgShapeLocation('storeB', 'SB', $b);

    orgShapeTerminal('term1', $a, $storeA, 'T1');

    expect(fn () => orgShapeTerminal('term2', $a, $storeA, 'T1'))->toThrow(QueryException::class);

    // Controls: a different code in the same branch, and the same code in a
    // different branch.
    orgShapeTerminal('term3', $a, $storeA, 'T2');
    orgShapeTerminal('term4', $b, $storeB, 'T1');

    expect(DB::connection(ORG_SHAPE_CONNECTION)->table('terminals')->count())->toBe(3);
});

it('refuses a terminal naming an unknown branch or location, and a location naming an unknown branch but not null (AC-2)', function () {
    orgShapeApply();

    $a = orgShapeBranch('branchA', 'A');
    $store = orgShapeLocation('storeA', 'SA', $a);
    $unknown = orgShapeId('nosuch');

    expect(fn () => orgShapeTerminal('termX', $unknown, $store, 'T1'))->toThrow(QueryException::class)
        ->and(fn () => orgShapeTerminal('termY', $a, $unknown, 'T1'))->toThrow(QueryException::class)
        ->and(fn () => orgShapeLocation('locX', 'LX', $unknown))->toThrow(QueryException::class);

    // The controls: the known ids are accepted, and a null branch is a
    // central warehouse.
    orgShapeTerminal('termOk', $a, $store, 'T1');
    orgShapeLocation('locNull', 'CENTRAL', null);

    expect(DB::connection(ORG_SHAPE_CONNECTION)->table('locations')->whereNull('branch_id')->count())->toBe(1);
});

it('refuses a second credential for one terminal, and a credential for no terminal (AC-2)', function () {
    orgShapeApply();

    $a = orgShapeBranch('branchA', 'A');
    $store = orgShapeLocation('storeA', 'SA', $a);
    $t1 = orgShapeTerminal('term1', $a, $store, 'T1');
    $t2 = orgShapeTerminal('term2', $a, $store, 'T2');

    $credential = static fn (string $terminalId): bool => DB::connection(ORG_SHAPE_CONNECTION)->table('terminal_credentials')->insert([
        'terminal_id' => $terminalId, 'status' => 'active', 'activated_at' => ORG_SHAPE_NOW,
    ]);

    $credential($t1);

    expect(fn () => $credential($t1))->toThrow(QueryException::class)
        ->and(fn () => $credential(orgShapeId('nosuch')))->toThrow(QueryException::class);

    // The control: a different terminal takes its own.
    $credential($t2);

    expect(DB::connection(ORG_SHAPE_CONNECTION)->table('terminal_credentials')->count())->toBe(2);
});

it('keys a device to its terminal, and its revoker to an employee (AC-2)', function () {
    orgShapeApply();

    $a = orgShapeBranch('branchA', 'A');
    $store = orgShapeLocation('storeA', 'SA', $a);
    $terminal = orgShapeTerminal('term1', $a, $store, 'T1');

    $device = static fn (string $terminalId, ?string $revokedBy = null): bool => DB::connection(ORG_SHAPE_CONNECTION)->table('devices')->insert([
        'id' => orgShapeId('dev'.bin2hex(random_bytes(4))), 'terminal_id' => $terminalId, 'fingerprint' => str_repeat('a', 64),
        'registered_at' => ORG_SHAPE_NOW, 'revoked_by' => $revokedBy, 'created_at' => ORG_SHAPE_NOW, 'updated_at' => ORG_SHAPE_NOW,
    ]);

    expect(fn () => $device(orgShapeId('nosuch')))->toThrow(QueryException::class)
        ->and(fn () => $device($terminal, orgShapeId('nobody')))->toThrow(QueryException::class);

    $device($terminal);

    expect(DB::connection(ORG_SHAPE_CONNECTION)->table('devices')->count())->toBe(1);
});
