<?php

use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| T-014 step 6 - the nine branch foreign keys, at migration level
|--------------------------------------------------------------------------
|
| AC-3, AC-5 (migration half), AC-7, AC-8 (static half) and AC-9, plus the five
| properties step 1 measured, kept as permanent assertions with a
| damaged-fixture control each (T-011 AC-15's shape).
|
| **V is found by name and V - 1 is built once.** Files 1 to V - 1 are applied
| in ONE migrate call to an in-memory database written out with VACUUM INTO,
| and every case copies that file; only V runs through the migrator, which is
| the subject. Nothing here writes a catalogue version as a literal.
|
| **Keys are enforced on the connection, and the migration is never wrapped in
| a transaction**: on SQLite `PRAGMA foreign_keys` is a no-op inside one, and
| the rebuild of a parent table would then be refused by its own children
| (T-014 Ruling 14, measured in step 1). The pre-check is the only data guard
| on the stand-in, which is why it is tried against every column in turn.
|
| **What a refusal must leave untouched is read, not assumed**: every row and
| the stored DDL of each of the nine tables, and the absence of a half-built
| `__temp__` table, compared before and after.
|
| This file names no tenant fixture class: it builds its own private stand-in,
| because a fixture that already carries the top of the tree could not show the
| state before V.
|
| No engine: SQLite files under storage/app, the driver asserted at open.
*/

const BFK_MIGRATION = '2026_10_22_000506_add_branch_foreign_keys';

const BFK_CONNECTION = 'bfk_case';

/** A 26-character id no row of any parent table holds. */
const BFK_SENTINEL = 'ZZLEAKZZ7Q9W3E5R1T8Y6U4I2O';

function bfkSet(): MigrationSet
{
    return MigrationSet::baseline(migrationTreeDirectory(MigrationTree::Tenant));
}

function bfkV(): int
{
    $version = bfkSet()->versionOf(BFK_MIGRATION);

    if ($version === null) {
        throw new RuntimeException('The shipped tenant tree does not hold '.BFK_MIGRATION.'.');
    }

    return $version;
}

function bfkTemplatePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'bfk-template.sqlite');
}

function bfkCasePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'bfk-case.sqlite');
}

/**
 * The nine keys: table, column, parent.
 *
 * @return list<array{string, string, string}>
 */
function bfkKeys(): array
{
    return [
        ['number_sequences', 'branch_id', 'branches'],
        ['employee_roles', 'branch_id', 'branches'],
        ['employee_permissions', 'branch_id', 'branches'],
        ['employee_branches', 'branch_id', 'branches'],
        ['branches', 'default_location_id', 'locations'],
        ['terminals', 'current_device_id', 'devices'],
        ['locations', 'created_by', 'employees'],
        ['locations', 'updated_by', 'employees'],
        ['terminal_credentials', 'revoked_by', 'employees'],
    ];
}

/**
 * Files 1 to V - 1 in one migrate call, into memory, then out to a file.
 */
function bfkBuildTemplate(): void
{
    $name = 'bfk_template_memory';

    config()->set('database.connections.'.$name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    expect(config('database.connections.'.$name.'.driver'))->toBe('sqlite');

    $paths = array_slice(bfkSet()->paths(), 0, bfkV() - 1);

    expect(Artisan::call('migrate', ['--database' => $name, '--path' => $paths, '--realpath' => true, '--force' => true]))->toBe(0);

    File::delete(bfkTemplatePath());

    DB::connection($name)->statement('vacuum into '.DB::connection($name)->getPdo()->quote(bfkTemplatePath()));

    DB::purge($name);
}

/**
 * A fresh copy of the V - 1 database, with keys enforced.
 */
function bfkOpen(): Connection
{
    DB::purge(BFK_CONNECTION);

    File::delete(bfkCasePath());
    File::copy(bfkTemplatePath(), bfkCasePath());

    config()->set('database.connections.'.BFK_CONNECTION, [
        'driver' => 'sqlite',
        'database' => bfkCasePath(),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.'.BFK_CONNECTION.'.driver'))->toBe('sqlite');

    /** @var Connection $connection */
    $connection = DB::connection(BFK_CONNECTION);

    $connection->statement('pragma synchronous = off');

    return $connection;
}

function bfkId(string $stem): string
{
    return strtoupper(str_pad(substr($stem, 0, 26), 26, '0'));
}

const BFK_NOW = '2026-10-22 09:00:00.000';

/**
 * Clean data at V - 1: two employees, two branches, a store, a terminal with
 * a device and a credential, and a row in each assignment table at the null
 * scope and at a branch.
 */
function bfkSeed(Connection $c): void
{
    $e1 = bfkId('EMP1');
    $e2 = bfkId('EMP2');
    $b1 = bfkId('BRA1');
    $b2 = bfkId('BRA2');
    $l1 = bfkId('LOC1');
    $t1 = bfkId('TER1');
    $d1 = bfkId('DEV1');

    foreach ([[$e1, 'E1'], [$e2, 'E2']] as [$id, $code]) {
        $c->table('employees')->insert([
            'id' => $id, 'code' => $code, 'first_name' => $code, 'display_name' => $code, 'status' => 'active',
            'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
        ]);
    }

    foreach ([[$b1, 'B1'], [$b2, 'B2'], [bfkId('BRA3'), 'B3']] as [$id, $code]) {
        $c->table('branches')->insert([
            'id' => $id, 'code' => $code, 'name' => $code, 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
            'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
        ]);
    }

    $c->table('locations')->insert([
        'id' => $l1, 'branch_id' => $b1, 'code' => 'L1', 'name' => 'Store', 'type' => 'branch_store',
        'created_by' => $e1, 'updated_by' => $e1, 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
    ]);
    $c->table('branches')->where('id', $b1)->update(['default_location_id' => $l1]);

    $c->table('terminals')->insert([
        'id' => $t1, 'branch_id' => $b1, 'location_id' => $l1, 'code' => 'T1', 'name' => 'Till', 'status' => 'active',
        'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
    ]);
    $c->table('devices')->insert([
        'id' => $d1, 'terminal_id' => $t1, 'fingerprint' => str_repeat('a', 64), 'registered_at' => BFK_NOW,
        'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
    ]);
    $c->table('terminals')->where('id', $t1)->update(['current_device_id' => $d1]);
    $c->table('terminal_credentials')->insert(['terminal_id' => $t1, 'status' => 'active', 'activated_at' => BFK_NOW, 'activated_by' => $e1]);

    $c->table('permissions')->insert(['key' => 'x.y', 'module' => 'm', 'group' => 'g', 'description' => 'd', 'is_branch_scoped' => true]);
    $c->table('roles')->insert(['id' => bfkId('ROL1'), 'key' => 'r', 'name' => 'r', 'is_system' => false, 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW]);

    $c->table('employee_roles')->insert([
        ['id' => bfkId('ER1'), 'employee_id' => $e1, 'role_id' => bfkId('ROL1'), 'branch_id' => null, 'granted_at' => BFK_NOW],
        ['id' => bfkId('ER2'), 'employee_id' => $e1, 'role_id' => bfkId('ROL1'), 'branch_id' => $b1, 'granted_at' => BFK_NOW],
    ]);
    $c->table('employee_permissions')->insert([
        ['id' => bfkId('EP1'), 'employee_id' => $e1, 'permission_key' => 'x.y', 'branch_id' => null, 'allowed' => true],
        ['id' => bfkId('EP2'), 'employee_id' => $e1, 'permission_key' => 'x.y', 'branch_id' => $b1, 'allowed' => false],
    ]);
    $c->table('employee_branches')->insert([
        ['employee_id' => $e1, 'branch_id' => $b1, 'is_default' => true, 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW],
        ['employee_id' => $e1, 'branch_id' => $b2, 'is_default' => false, 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW],
        ['employee_id' => $e2, 'branch_id' => $b2, 'is_default' => true, 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW],
    ]);
    $c->table('number_sequences')->insert([
        ['id' => bfkId('NS1'), 'branch_id' => null, 'document_type' => 'sale', 'reset_period' => 'never', 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW],
        ['id' => bfkId('NS2'), 'branch_id' => $b1, 'document_type' => 'sale', 'reset_period' => 'never', 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW],
    ]);
}

/**
 * Put one dirty value into the column of one key, without touching any other.
 */
function bfkPlantDirty(Connection $c, string $table, string $column, string $value, int $times = 1): void
{
    for ($i = 0; $i < $times; $i++) {
        $suffix = strtoupper(substr(md5($table.$column.$i), 0, 6));

        match ($table.'.'.$column) {
            'number_sequences.branch_id' => $c->table('number_sequences')->insert([
                'id' => bfkId('NSD'.$suffix), 'branch_id' => $value, 'document_type' => 'dirty'.$i, 'reset_period' => 'never',
                'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
            ]),
            'employee_roles.branch_id' => $c->table('employee_roles')->insert([
                'id' => bfkId('ERD'.$suffix), 'employee_id' => bfkId('EMP2'), 'role_id' => bfkId('ROL1'), 'branch_id' => $value, 'granted_at' => BFK_NOW,
            ]),
            'employee_permissions.branch_id' => $c->table('employee_permissions')->insert([
                'id' => bfkId('EPD'.$suffix), 'employee_id' => bfkId('EMP2'), 'permission_key' => 'x.y', 'branch_id' => $value, 'allowed' => true,
            ]),
            'employee_branches.branch_id' => $c->table('employee_branches')->insert([
                'employee_id' => bfkId('EMP2'), 'branch_id' => $value, 'is_default' => false, 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
            ]),
            'branches.default_location_id' => $c->table('branches')->where('id', bfkId('BRA2'))->update(['default_location_id' => $value]),
            'terminals.current_device_id' => $c->table('terminals')->where('id', bfkId('TER1'))->update(['current_device_id' => $value]),
            'locations.created_by' => $c->table('locations')->where('id', bfkId('LOC1'))->update(['created_by' => $value]),
            'locations.updated_by' => $c->table('locations')->where('id', bfkId('LOC1'))->update(['updated_by' => $value]),
            'terminal_credentials.revoked_by' => $c->table('terminal_credentials')->where('terminal_id', bfkId('TER1'))->update(['revoked_by' => $value]),
            default => throw new InvalidArgumentException('No planting is defined for '.$table.'.'.$column),
        };
    }
}

/**
 * Undo bfkPlantDirty() for one key: the data a person would correct.
 */
function bfkClean(Connection $c, string $table, string $column): void
{
    $c->table($table)->where($column, '!=', '')->whereNotNull($column)
        ->whereNotExists(function ($query) use ($table, $column) {
            $parent = collect(bfkKeys())->first(static fn (array $k): bool => $k[0] === $table && $k[1] === $column)[2];
            $query->from($parent)->whereColumn($parent.'.id', $table.'.'.$column);
        })
        ->when(
            in_array($table, ['number_sequences', 'employee_roles', 'employee_permissions', 'employee_branches'], true),
            static fn ($query) => $query->delete(),
            static fn ($query) => $query->update([$column => null])
        );
}

/**
 * Every row and the stored DDL of each of the nine tables, and any temp
 * table left behind.
 *
 * @return array<string, string>
 */
function bfkSnapshot(Connection $c): array
{
    $snap = [];

    foreach (array_values(array_unique(array_column(bfkKeys(), 0))) as $table) {
        $snap[$table.' rows'] = json_encode($c->select('select * from "'.$table.'" order by 1, 2'), JSON_THROW_ON_ERROR);
        $snap[$table.' ddl'] = (string) ($c->selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table])->sql ?? '');
    }

    $snap['temp tables'] = json_encode(array_column(
        $c->select("select name from sqlite_master where name like '%__temp__%' order by name"),
        'name'
    ), JSON_THROW_ON_ERROR);

    return $snap;
}

/**
 * The foreign keys of one table as `from -> table.to`, sorted.
 *
 * @return list<string>
 */
function bfkTableKeys(Connection $c, string $table): array
{
    $keys = [];

    foreach ($c->select('pragma foreign_key_list("'.$table.'")') as $key) {
        $key = (array) $key;
        $keys[] = $key['from'].' -> '.$key['table'].'.'.$key['to'];
    }

    sort($keys);

    return $keys;
}

/**
 * Run V through the migrator on the open case, as the runner's applier does.
 */
function bfkApply(): int
{
    return Artisan::call('migrate', [
        '--database' => BFK_CONNECTION,
        '--path' => [bfkSet()->pathFor(bfkV())],
        '--realpath' => true,
        '--force' => true,
    ]);
}

/**
 * The message a refused run threw, or null when it ran.
 */
function bfkRefusal(): ?string
{
    try {
        bfkApply();
    } catch (Throwable $thrown) {
        return $thrown->getMessage();
    }

    return null;
}

beforeEach(function () {
    bfkBuildTemplate();
});

afterEach(function () {
    DB::purge(BFK_CONNECTION);

    File::delete(bfkCasePath());
    File::delete(bfkTemplatePath());
});

/*
|--------------------------------------------------------------------------
| AC-4 (ordering) and AC-3 - the keys exist, enforced, with a before control
|--------------------------------------------------------------------------
*/

it('sorts after every migration that creates a table it keys (AC-4)', function () {
    $keyed = array_unique(array_merge(...array_map(static fn (array $k): array => [$k[0], $k[2]], bfkKeys())));

    foreach ($keyed as $table) {
        $creator = null;

        foreach (bfkSet()->catalogue() as $entry) {
            if (preg_match('/_create_'.$table.'_table$/', $entry->migration) === 1) {
                $creator = $entry->version;
            }
        }

        expect($creator)->not->toBeNull("no migration creates [{$table}]")
            ->and($creator)->toBeLessThan(bfkV(), "[{$table}] is created after the key step");
    }

    expect(count($keyed))->toBeGreaterThan(8);
});

it('accepts an unknown branch in each of the four tables before the keys exist, and refuses it after (AC-3)', function () {
    $c = bfkOpen();
    bfkSeed($c);

    $insert = [
        'number_sequences' => fn (string $branch) => $c->table('number_sequences')->insert([
            'id' => bfkId('NSX'.substr($branch, 0, 3)), 'branch_id' => $branch, 'document_type' => 'x', 'reset_period' => 'never',
            'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
        ]),
        'employee_roles' => fn (string $branch) => $c->table('employee_roles')->insert([
            'id' => bfkId('ERX'.substr($branch, 0, 3)), 'employee_id' => bfkId('EMP2'), 'role_id' => bfkId('ROL1'), 'branch_id' => $branch, 'granted_at' => BFK_NOW,
        ]),
        'employee_permissions' => fn (string $branch) => $c->table('employee_permissions')->insert([
            'id' => bfkId('EPX'.substr($branch, 0, 3)), 'employee_id' => bfkId('EMP2'), 'permission_key' => 'x.y', 'branch_id' => $branch, 'allowed' => true,
        ]),
        'employee_branches' => fn (string $branch) => $c->table('employee_branches')->insert([
            'employee_id' => bfkId('EMP2'), 'branch_id' => $branch, 'is_default' => false, 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
        ]),
    ];

    // The control, per table: at V - 1 the unknown id is accepted.
    foreach ($insert as $table => $do) {
        $do('QQQQUNKNOWN00000000000000');
        expect($c->table($table)->where('branch_id', 'QQQQUNKNOWN00000000000000')->count())->toBe(1, "[{$table}] before the key");
        $c->table($table)->where('branch_id', 'QQQQUNKNOWN00000000000000')->delete();
    }

    expect(bfkApply())->toBe(0);

    foreach ($insert as $table => $do) {
        // Refused for an unknown id.
        expect(fn () => $do('QQQQUNKNOWN00000000000000'))->toThrow(QueryException::class);

        // Accepted for an existing one.
        $do(bfkId('BRA3'));
    }

    expect($c->table('employee_roles')->where('branch_id', bfkId('BRA3'))->count())->toBe(1)
        ->and($c->table('number_sequences')->where('branch_id', bfkId('BRA3'))->count())->toBe(1)
        ->and($c->table('employee_branches')->where('branch_id', bfkId('BRA3'))->count())->toBe(1);

    // Null is accepted for the three tables whose column is nullable.
    $c->table('number_sequences')->insert(['id' => bfkId('NSN'), 'branch_id' => null, 'document_type' => 'null-ok', 'reset_period' => 'never', 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW]);
    $c->table('employee_roles')->insert(['id' => bfkId('ERN'), 'employee_id' => bfkId('EMP2'), 'role_id' => bfkId('ROL1'), 'branch_id' => null, 'granted_at' => BFK_NOW]);
    $c->table('employee_permissions')->insert(['id' => bfkId('EPN'), 'employee_id' => bfkId('EMP2'), 'permission_key' => 'x.y', 'branch_id' => null, 'allowed' => true]);

    expect($c->table('number_sequences')->whereNull('branch_id')->count())->toBe(2);

    // And the other five keys bite too.
    expect(fn () => $c->table('branches')->where('id', bfkId('BRA2'))->update(['default_location_id' => BFK_SENTINEL]))->toThrow(QueryException::class)
        ->and(fn () => $c->table('terminals')->where('id', bfkId('TER1'))->update(['current_device_id' => BFK_SENTINEL]))->toThrow(QueryException::class)
        ->and(fn () => $c->table('locations')->where('id', bfkId('LOC1'))->update(['created_by' => BFK_SENTINEL]))->toThrow(QueryException::class)
        ->and(fn () => $c->table('locations')->where('id', bfkId('LOC1'))->update(['updated_by' => BFK_SENTINEL]))->toThrow(QueryException::class)
        ->and(fn () => $c->table('terminal_credentials')->where('terminal_id', bfkId('TER1'))->update(['revoked_by' => BFK_SENTINEL]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| AC-5 - the data-clean pre-check, each key in turn
|--------------------------------------------------------------------------
*/

it('refuses a dirty column before altering anything, naming table, column and count and no value, and succeeds once cleaned (AC-5)', function (string $table, string $column) {
    $c = bfkOpen();
    bfkSeed($c);
    bfkPlantDirty($c, $table, $column, BFK_SENTINEL);

    $before = bfkSnapshot($c);
    $keysBefore = [];

    foreach (array_unique(array_column(bfkKeys(), 0)) as $t) {
        $keysBefore[$t] = bfkTableKeys($c, $t);
    }

    $message = bfkRefusal();

    expect($message)->not->toBeNull()
        ->and($message)->toContain($table.'.'.$column.': 1 row(s)')
        ->and($message)->not->toContain(BFK_SENTINEL)
        ->and($message)->not->toContain(strrev(BFK_SENTINEL))
        ->and($message)->toContain('Nothing was changed');

    // Byte-identical rows and DDL, no temp table, no key added anywhere, and
    // not a single other key of the nine added either.
    expect(bfkSnapshot($c))->toBe($before);

    foreach ($keysBefore as $t => $keys) {
        expect(bfkTableKeys($c, $t))->toBe($keys, "[{$t}] keys changed by a refused run");
    }

    // Corrected data, same migration: it succeeds.
    bfkClean($c, $table, $column);

    expect(bfkRefusal())->toBeNull()
        ->and(bfkTableKeys($c, $table))->toContain($column.' -> '.collect(bfkKeys())->first(static fn (array $k): bool => $k[0] === $table && $k[1] === $column)[2].'.id');
})->with(array_map(static fn (array $k): array => [$k[0], $k[1]], bfkKeys()));

it('counts a lower-case spelling of an existing id as offending, an exact comparison (AC-5)', function () {
    $c = bfkOpen();
    bfkSeed($c);
    bfkPlantDirty($c, 'employee_roles', 'branch_id', strtolower(bfkId('BRA1')));

    expect(bfkRefusal())->toContain('employee_roles.branch_id: 1 row(s)');
});

it('passes a clean tenant and one whose columns are all null (AC-5 controls)', function () {
    $clean = bfkOpen();
    bfkSeed($clean);

    expect(bfkRefusal())->toBeNull();

    $nulls = bfkOpen();
    bfkSeed($nulls);

    // Rows at a branch go first: once the nullable columns are null, a second
    // company-wide row for one (employee, role) pair would be refused by the
    // sentinel, which is a different rule from the one under test.
    $nulls->table('employee_roles')->where('id', bfkId('ER2'))->delete();
    $nulls->table('employee_permissions')->where('id', bfkId('EP2'))->delete();
    $nulls->table('number_sequences')->where('id', bfkId('NS2'))->delete();
    $nulls->table('branches')->update(['default_location_id' => null]);
    $nulls->table('terminals')->update(['current_device_id' => null]);
    $nulls->table('locations')->update(['created_by' => null, 'updated_by' => null]);
    $nulls->table('terminal_credentials')->update(['revoked_by' => null]);

    // Every nullable key column of the nine is null or absent now.
    expect($nulls->table('employee_roles')->whereNotNull('branch_id')->count())->toBe(0)
        ->and($nulls->table('number_sequences')->whereNotNull('branch_id')->count())->toBe(0);
    expect(bfkRefusal())->toBeNull();
});

it('reports every dirty table in one message when two are dirty (AC-5)', function () {
    $c = bfkOpen();
    bfkSeed($c);
    bfkPlantDirty($c, 'number_sequences', 'branch_id', BFK_SENTINEL, 2);
    bfkPlantDirty($c, 'terminals', 'current_device_id', strrev(BFK_SENTINEL));

    $before = bfkSnapshot($c);
    $message = bfkRefusal();

    expect($message)->toContain('number_sequences.branch_id: 2 row(s)')
        ->and($message)->toContain('terminals.current_device_id: 1 row(s)')
        ->and($message)->not->toContain(BFK_SENTINEL)
        ->and($message)->not->toContain(strrev(BFK_SENTINEL))
        ->and(bfkSnapshot($c))->toBe($before);
});

/*
|--------------------------------------------------------------------------
| AC-7 - restrict
|--------------------------------------------------------------------------
*/

it('refuses to delete a referenced branch, and deletes an unreferenced one (AC-7)', function () {
    $c = bfkOpen();
    bfkSeed($c);
    $c->table('branches')->insert([
        'id' => bfkId('BRA9'), 'code' => 'B9', 'name' => 'Unreferenced', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
        'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
    ]);

    expect(bfkApply())->toBe(0);

    // BRA2 is referenced by employee_branches only; BRA1 by every table.
    foreach (['BRA1', 'BRA2'] as $stem) {
        expect(fn () => $c->table('branches')->where('id', bfkId($stem))->delete())->toThrow(QueryException::class);
    }

    // The control: the unreferenced one goes, so the refusal is the key's.
    expect($c->table('branches')->where('id', bfkId('BRA9'))->delete())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Step 1's properties, permanent, each with a damaged-fixture control
|--------------------------------------------------------------------------
*/

/**
 * What a rebuild must keep, as a list of the properties that failed.
 *
 * @param  array<string, string>  $before  bfkSnapshot() taken at V - 1
 * @param  array<string, list<string>>  $keysBefore
 * @return list<string>
 */
function bfkRebuildFailures(Connection $c, array $before, array $keysBefore): array
{
    $failures = [];
    $after = bfkSnapshot($c);

    foreach ($before as $name => $value) {
        if (str_ends_with($name, ' rows') && $after[$name] !== $value) {
            $failures[] = 'rows changed: '.$name;
        }
    }

    foreach ($keysBefore as $table => $keys) {
        $now = bfkTableKeys($c, $table);

        foreach ($keys as $key) {
            if (! in_array($key, $now, true)) {
                $failures[] = 'existing key lost: '.$table.' '.$key;
            }
        }
    }

    foreach (bfkKeys() as [$table, $column, $parent]) {
        if (! in_array($column.' -> '.$parent.'.id', bfkTableKeys($c, $table), true)) {
            $failures[] = 'new key missing: '.$table.'.'.$column;
        }
    }

    foreach (['employee_roles', 'employee_permissions', 'number_sequences'] as $table) {
        foreach ($c->select("select branch_id, branch_id_key from \"{$table}\"") as $row) {
            $row = (array) $row;

            if ($row['branch_id_key'] !== ($row['branch_id'] ?? '-')) {
                $failures[] = 'generated column wrong: '.$table;
            }
        }
    }

    foreach ($c->select('select employee_id, is_default, default_employee_id from employee_branches') as $row) {
        $row = (array) $row;

        if ($row['default_employee_id'] !== ($row['is_default'] ? $row['employee_id'] : null)) {
            $failures[] = 'generated column wrong: employee_branches';
        }
    }

    $duplicates = [
        'sentinel duplicate accepted: number_sequences' => static fn () => $c->table('number_sequences')->insert([
            'id' => bfkId('NSD1'), 'branch_id' => null, 'document_type' => 'sale', 'reset_period' => 'never', 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
        ]),
        'sentinel duplicate accepted: employee_roles' => static fn () => $c->table('employee_roles')->insert([
            'id' => bfkId('ERD1'), 'employee_id' => bfkId('EMP1'), 'role_id' => bfkId('ROL1'), 'branch_id' => null, 'granted_at' => BFK_NOW,
        ]),
        'second default accepted: employee_branches' => static fn () => $c->table('employee_branches')->insert([
            'employee_id' => bfkId('EMP1'), 'branch_id' => bfkId('BRA9'), 'is_default' => true, 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
        ]),
        'composite primary key duplicate accepted: employee_branches' => static fn () => $c->table('employee_branches')->insert([
            'employee_id' => bfkId('EMP1'), 'branch_id' => bfkId('BRA2'), 'is_default' => false, 'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
        ]),
    ];

    if (! $c->table('branches')->where('id', bfkId('BRA9'))->exists()) {
        $c->table('branches')->insert([
            'id' => bfkId('BRA9'), 'code' => 'B9', 'name' => 'Spare', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
            'created_at' => BFK_NOW, 'updated_at' => BFK_NOW,
        ]);
    }

    foreach ($duplicates as $label => $attempt) {
        try {
            $attempt();
            $failures[] = $label;
        } catch (QueryException) {
            // Refused, as it must be.
        }
    }

    return $failures;
}

it('keeps rows, generated columns, existing keys and the sentinel across the rebuilds (step 1, permanent)', function () {
    $c = bfkOpen();
    bfkSeed($c);

    $before = bfkSnapshot($c);
    $keysBefore = [];

    foreach (array_unique(array_column(bfkKeys(), 0)) as $t) {
        $keysBefore[$t] = bfkTableKeys($c, $t);
    }

    expect(bfkApply())->toBe(0)
        ->and($c->select('pragma foreign_key_check'))->toBe([])
        ->and(bfkRebuildFailures($c, $before, $keysBefore))->toBe([]);
});

it('reports a lost row, a lost existing key and a missing new key, so the property check can fail (step 1 controls)', function () {
    // Damaged fixture 1: a row is gone after the rebuild.
    $c = bfkOpen();
    bfkSeed($c);

    $before = bfkSnapshot($c);
    $keysBefore = [];

    foreach (array_unique(array_column(bfkKeys(), 0)) as $t) {
        $keysBefore[$t] = bfkTableKeys($c, $t);
    }

    bfkApply();
    $c->table('number_sequences')->where('id', bfkId('NS2'))->delete();

    expect(bfkRebuildFailures($c, $before, $keysBefore))->toContain('rows changed: number_sequences rows');

    // Damaged fixture 2: an existing key is dropped from a rebuilt table.
    $d = bfkOpen();
    bfkSeed($d);
    bfkApply();
    Schema::connection(BFK_CONNECTION)->table('employee_roles', static function (Blueprint $table): void {
        $table->dropForeign(['granted_by']);
    });

    expect(bfkRebuildFailures($d, $before, $keysBefore))->toContain('existing key lost: employee_roles granted_by -> employees.id');

    // Damaged fixture 3: the migration did not run at all.
    $e = bfkOpen();
    bfkSeed($e);

    expect(bfkRebuildFailures($e, $before, $keysBefore))->toContain('new key missing: number_sequences.branch_id');
});

/*
|--------------------------------------------------------------------------
| The retry guard
|--------------------------------------------------------------------------
*/

it('skips a key already present, so a run that stopped between two keys finishes with one key per column', function () {
    $c = bfkOpen();
    bfkSeed($c);

    // A scratch step standing in for "the earlier run got this far".
    Schema::connection(BFK_CONNECTION)->table('employee_roles', static function (Blueprint $table): void {
        $table->foreign('branch_id')->references('id')->on('branches');
    });

    expect(bfkApply())->toBe(0);

    foreach (bfkKeys() as [$table, $column, $parent]) {
        $count = count(array_filter(
            $c->select('pragma foreign_key_list("'.$table.'")'),
            static fn (object $key): bool => ((array) $key)['from'] === $column
        ));

        expect($count)->toBe(1, "[{$table}.{$column}] must carry exactly one key");
    }
});

/*
|--------------------------------------------------------------------------
| AC-8 and AC-9 - what the file says and what it leaves alone
|--------------------------------------------------------------------------
*/

/**
 * @return array<string, mixed>
 */
function bfkCatalogueRow(): array
{
    $rows = json_decode((string) File::get(base_path((string) config('schema.catalogue_fixture'))), true, flags: JSON_THROW_ON_ERROR);

    foreach ($rows as $row) {
        if ($row['migration'] === BFK_MIGRATION) {
            return $row;
        }
    }

    throw new RuntimeException('The committed catalogue holds no row for '.BFK_MIGRATION.'.');
}

function bfkSource(): string
{
    return (string) File::get((string) bfkSet()->pathFor(bfkV()));
}

it('is declared contract and not destructive, breaks no static rule, and has no exemption (AC-4, AC-8)', function () {
    $row = bfkCatalogueRow();

    expect($row['phase'])->toBe('contract')
        ->and($row['is_destructive'])->toBeFalse()
        ->and($row['requires_offline'])->toBeFalse()
        ->and(SourceRules::inspect(bfkSource(), MigrationTree::Tenant))->toBe([])
        ->and(SourceRules::isDestructive(bfkSource()))->toBeFalse();
});

it('has no driver token, no referential action, no drop, no rename and no down() (AC-6, AC-8, Ruling 14)', function () {
    $code = SourceRules::codeOnly(bfkSource());

    $banned = [
        'a driver gate' => '/getDriverName|[\'"](?:sqlite|mysql|mariadb)[\'"]/i',
        'a referential action' => '/->\s*(?:onDelete|onUpdate|cascadeOnDelete|nullOnDelete|restrictOnDelete|cascadeOnUpdate)\s*\(/',
        'a drop' => '/->\s*drop[A-Za-z]*\s*\(/',
        'a rename' => '/->\s*rename[A-Za-z]*\s*\(/',
        'a truncate' => '/\btruncate\b/i',
        'a down()' => '/function\s+down\s*\(/',
    ];

    foreach ($banned as $what => $pattern) {
        expect(preg_match($pattern, $code))->toBe(0, "the migration contains {$what}");
    }

    // Liveness: the same patterns DO match when the thing is there.
    expect(preg_match($banned['a referential action'], '$t->foreign("a")->cascadeOnDelete();'))->toBe(1)
        ->and(preg_match($banned['a driver gate'], "if (\$c->getDriverName() === 'sqlite') {}"))->toBe(1)
        ->and(preg_match($banned['a down()'], 'public function down(): void {}'))->toBe(1);
});

it('says where each key was owed, which columns stay unguarded, and that two runs are needed, without a version (AC-8, AC-9)', function () {
    // Only the docblock, whitespace-collapsed, so a line wrap cannot hide a phrase.
    preg_match('#/\*(.*?)\*/#s', bfkSource(), $matches);

    $docblock = (string) preg_replace('/\s+(?:\*\s+)?/', ' ', (string) ($matches[1] ?? ''));

    foreach (['T-010 D-1', 'T-008 D-5', 'T-014 D-2', 'two runs', 'audit_logs.branch_id', 'outbox_events.branch_id',
        'access_log.branch_id', 'access_log.terminal_id', 'FP-209 owes its foreign key', 'Contract phase'] as $phrase) {
        expect($docblock)->toContain($phrase);
    }

    // No catalogue version in prose (T-011 D-6): a digit string after "version" or "--to=".
    expect(preg_match('/--to=\s*\d|version\s+\d/i', $docblock))->toBe(0);
});

it('leaves every shipped migration and every earlier catalogue row exactly as it was (AC-8)', function () {
    // The pinned checksums of versions 1 to 15 as they stood at the T-011
    // tip. A shipped file edited by this task moves its checksum, and the
    // committed fixture would then disagree with this copy.
    $pinned = [
        1 => '22d5106399666935d64241d65037985a07ad1ccc9af9b06e83f7e38c6a119073',
        2 => '510c0f4a3f3d9f756897f034f1ec5d9e84efb5c7bdd9d0a0577e573e9dcb6f5f',
        3 => '46ed79a75b5c605939f8610ee18c27170f9b41c84e8e2e7f8780d5503eb3ef01',
        4 => '976937277c637c49bb50ce420eeb31cddfa43721e414c6fe3f370c84d5ef35a5',
        5 => 'e3ac4eee50003809f5f74eedafc2cddf1ba90c5ec9bf12d1be53bac57f94d10c',
        6 => '4e1c09b9d5690be55e6135f0f558db14ec880285ffee2c3a1d8f4241df3584a6',
        7 => '9575c491a85f2e6a59095c1c9f0e0d52eac50073fd06f7887f963b80a9d28576',
        8 => '33024734de3bbef94cd0b55d229f70d332d1636383eeab62b28c0bbd9e83f3a8',
        9 => '362994fc28366c16a2f21b2adf9b0c324b27a81d134b29bd2fe0ea6e29678820',
        10 => '8e846c38e2dee5670d7b6bf587d49134a9f71b18886aa2bc4162c454c22d873d',
        11 => 'c2853ebf9a052171af7e1152f4aa3e7f33ac25225d5c0eb7107a9bd35257c0fc',
        12 => '33aee59755080ccf76d60bfdb25158e11d29f138728c0c7a5bcfe3a014dffe90',
        13 => 'f7fb824ecea6ed0394b24ea8d8b54054174aeaadf83e93669668611037cb44fb',
        14 => '418527572c2ee733b1c2d2922d80b778fa361c921da3e688dfeeec3a08963998',
        15 => '4dd75595a674f0b344e93fc9faa77fac00001ca2ffeb37cd26df976ee33c098d',
    ];

    $rows = json_decode((string) File::get(base_path((string) config('schema.catalogue_fixture'))), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];

    foreach ($rows as $row) {
        if ($row['version'] <= 15) {
            $actual[$row['version']] = $row['checksum'];
        }
    }

    expect($actual)->toBe($pinned)
        ->and(count($pinned))->toBe(15);

    // And the checksum is a property of the file on disk, not of the fixture.
    foreach (bfkSet()->catalogue() as $entry) {
        if ($entry->version <= 15) {
            expect($entry->checksum)->toBe($pinned[$entry->version], "[{$entry->migration}] has been edited");
        }
    }
});

it('leaves the four history columns without a key (AC-9)', function () {
    $c = bfkOpen();
    bfkSeed($c);

    expect(bfkApply())->toBe(0);

    foreach ([['audit_logs', 'branch_id'], ['outbox_events', 'branch_id'], ['access_log', 'branch_id'], ['access_log', 'terminal_id']] as [$table, $column]) {
        $from = array_map(static fn (object $key): string => ((array) $key)['from'], $c->select('pragma foreign_key_list("'.$table.'")'));

        expect(in_array($column, $from, true))->toBeFalse("[{$table}.{$column}] gained a key");
    }
});
