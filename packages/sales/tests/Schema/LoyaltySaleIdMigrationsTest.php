<?php

namespace Flexipos\Sales\Tests\Schema;

use Flexipos\Kernel\Schema\Support\MigrationPhase;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| loyalty_transactions.sale_id: expand, then contract (T-030 plan step 7,
| AC-45; D-34, F-9, R-33)
|--------------------------------------------------------------------------
|
| The same shape as ProductTaxGroupMigrationsTest: the files before the
| expand step are applied once to an in-memory database written out with
| VACUUM INTO, every case copies that file, and the step under test runs
| through the migrator (which is the subject). Each version is found by name,
| never written as a literal.
|
| What this file proves:
|
| - expand adds one nullable, key-less column and the unique (sale_id, type)
|   key, and every loyalty row seeded before it survives with a NULL sale_id;
|   two rows for one (sale, type) refuse, and two NULLs coexist;
| - the contract file refuses dirty data naming the table, the column and a
|   COUNT - never an id - and changes nothing; adds the one key when clean;
|   keeps rows and the unique key through the SQLite rebuild; is a no-op when
|   re-run;
| - both catalogue rows are NOT destructive (T-008 D-10), the contract file is
|   T-030's only one (T-014 D-31), and it is the top of the tree;
| - the contract file contains no change() call, so it is additive only.
|
| No engine: SQLite files under storage/app, the driver asserted at open.
*/

const LSM_EXPAND = '2026_11_10_003009_add_sale_id_to_loyalty_transactions_table';

const LSM_CONTRACT = '2026_11_10_003010_add_loyalty_sale_foreign_key';

const LSM_CONNECTION = 'lsm_case';

const LSM_NOW = '2026-11-10 09:00:00.000';

/** A 26-character id no sale holds. */
const LSM_SENTINEL = 'ZZLEAKZZ7Q9W3E5R1T8Y6U4I2O';

function lsmSet(): MigrationSet
{
    return MigrationSet::baseline(migrationTreeDirectory(MigrationTree::Tenant));
}

function lsmVersion(string $migration): int
{
    $version = lsmSet()->versionOf($migration);

    if ($version === null) {
        throw new \RuntimeException('The shipped tenant tree does not hold '.$migration.'.');
    }

    return $version;
}

function lsmTemplatePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'lsm-template.sqlite');
}

function lsmCasePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'lsm-case.sqlite');
}

/**
 * Every file before the expand step, in one migrate call, into memory, then
 * out to a file.
 */
function lsmBuildTemplate(): void
{
    $name = 'lsm_template_memory';

    config()->set('database.connections.'.$name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    expect(config('database.connections.'.$name.'.driver'))->toBe('sqlite');

    $paths = array_slice(lsmSet()->paths(), 0, lsmVersion(LSM_EXPAND) - 1);

    expect(Artisan::call('migrate', ['--database' => $name, '--path' => $paths, '--realpath' => true, '--force' => true]))->toBe(0);

    File::delete(lsmTemplatePath());

    DB::connection($name)->statement('vacuum into '.DB::connection($name)->getPdo()->quote(lsmTemplatePath()));

    DB::purge($name);
}

/**
 * A fresh copy of the before-expand database, with keys enforced.
 */
function lsmOpen(): Connection
{
    DB::purge(LSM_CONNECTION);

    File::delete(lsmCasePath());
    File::copy(lsmTemplatePath(), lsmCasePath());

    config()->set('database.connections.'.LSM_CONNECTION, [
        'driver' => 'sqlite',
        'database' => lsmCasePath(),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.'.LSM_CONNECTION.'.driver'))->toBe('sqlite');

    /** @var Connection $connection */
    $connection = DB::connection(LSM_CONNECTION);

    $connection->statement('pragma synchronous = off');

    return $connection;
}

function lsmApply(string $migration): int
{
    return Artisan::call('migrate', [
        '--database' => LSM_CONNECTION,
        '--path' => [lsmSet()->pathFor(lsmVersion($migration))],
        '--realpath' => true,
        '--force' => true,
    ]);
}

/**
 * Call one migration's `up()` a second time directly: the migrator would skip
 * a recorded file, and "re-run" is the thing under test.
 */
function lsmRunUpAgain(string $migration): void
{
    $migrationObject = require (string) lsmSet()->pathFor(lsmVersion($migration));
    $previous = DB::getDefaultConnection();

    DB::setDefaultConnection(LSM_CONNECTION);

    try {
        $migrationObject->up();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/**
 * The message a refused run threw, or null when it ran.
 */
function lsmRefusal(string $migration): ?string
{
    try {
        lsmApply($migration);
    } catch (\Throwable $thrown) {
        return $thrown->getMessage();
    }

    return null;
}

function lsmId(string $stem): string
{
    return strtoupper(str_pad(substr($stem, 0, 26), 26, '0'));
}

/**
 * A customer, a program and an employee, then one loyalty row per given
 * `[id stem, sale id|null, type]`; and, for each sale id given that is not the
 * sentinel, a real sale (with its branch, location and employee).
 *
 * @param  list<array{0: string, 1: ?string, 2: string}>  $rows
 */
function lsmSeed(Connection $c, array $rows): void
{
    $c->table('employees')->insert([
        'id' => lsmId('EMP1'), 'code' => 'e1', 'first_name' => 'Jo', 'display_name' => 'Jo', 'status' => 'active',
        'created_at' => LSM_NOW, 'updated_at' => LSM_NOW,
    ]);
    $c->table('branches')->insert([
        'id' => lsmId('BR1'), 'code' => 'br1', 'name' => 'Branch', 'timezone' => 'UTC', 'currency' => 'USD',
        'created_at' => LSM_NOW, 'updated_at' => LSM_NOW,
    ]);
    $c->table('locations')->insert([
        'id' => lsmId('LOC1'), 'branch_id' => lsmId('BR1'), 'code' => 'l1', 'name' => 'Loc', 'type' => 'branch_store',
        'created_at' => LSM_NOW, 'updated_at' => LSM_NOW,
    ]);
    $c->table('customers')->insert(['id' => lsmId('CUST1'), 'created_at' => LSM_NOW, 'updated_at' => LSM_NOW, 'name' => 'C1']);
    $c->table('loyalty_programs')->insert(['id' => lsmId('PROG1'), 'created_at' => LSM_NOW, 'updated_at' => LSM_NOW, 'name' => 'P1']);

    $sales = [];

    foreach ($rows as [$stem, $saleId, $type]) {
        if ($saleId !== null && $saleId !== LSM_SENTINEL && ! in_array($saleId, $sales, true)) {
            $c->table('sales')->insert([
                'id' => $saleId, 'created_at' => LSM_NOW, 'updated_at' => LSM_NOW, 'branch_id' => lsmId('BR1'),
                'location_id' => lsmId('LOC1'), 'employee_id' => lsmId('EMP1'), 'type' => 'sale', 'status' => 'completed',
                'business_date' => '2026-11-10', 'business_date_source' => 'server', 'currency' => 'USD', 'subtotal' => '0.0000',
                'discount_total' => '0.0000', 'tax_total' => '0.0000', 'service_charge' => '0.0000', 'rounding' => '0.0000',
                'total' => '0.0000', 'paid_total' => '0.0000', 'change_due' => '0.0000',
            ]);
            $sales[] = $saleId;
        }
    }

    foreach ($rows as [$stem, $saleId, $type]) {
        $row = [
            'id' => lsmId($stem), 'customer_id' => lsmId('CUST1'), 'program_id' => lsmId('PROG1'), 'type' => $type,
            'points' => '10.0000', 'balance_after' => '10.0000', 'created_at' => LSM_NOW,
        ];

        if ($saleId !== null && $c->getSchemaBuilder()->hasColumn('loyalty_transactions', 'sale_id')) {
            $row['sale_id'] = $saleId;
        }

        $c->table('loyalty_transactions')->insert($row);
    }
}

/**
 * The foreign keys of one table as `from -> table.to`, sorted.
 *
 * @return list<string>
 */
function lsmTableKeys(Connection $c, string $table): array
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
 * @return array<string, string>
 */
function lsmSnapshot(Connection $c): array
{
    return [
        'rows' => json_encode($c->select('select * from "loyalty_transactions" order by id'), JSON_THROW_ON_ERROR),
        'ddl' => (string) ($c->selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', 'loyalty_transactions'])->sql ?? ''),
        'temp tables' => json_encode(array_column(
            $c->select("select name from sqlite_master where name like '%__temp__%' order by name"),
            'name'
        ), JSON_THROW_ON_ERROR),
    ];
}

beforeEach(function () {
    lsmBuildTemplate();
});

afterEach(function () {
    DB::purge(LSM_CONNECTION);

    File::delete(lsmCasePath());
    File::delete(lsmTemplatePath());
});

/*
|--------------------------------------------------------------------------
| Expand
|--------------------------------------------------------------------------
*/

it('expand: adds one nullable, key-less column and the unique key, and every seeded loyalty row survives with a NULL sale_id', function () {
    $c = lsmOpen();
    lsmSeed($c, [['L1', null, 'earn'], ['L2', null, 'earn'], ['L3', null, 'redeem']]);

    expect(array_column($c->select('pragma table_info("loyalty_transactions")'), 'name'))->not->toContain('sale_id');

    expect(lsmApply(LSM_EXPAND))->toBe(0);

    $columns = collect($c->select('pragma table_info("loyalty_transactions")'))->keyBy('name');

    expect($columns)->toHaveKey('sale_id')
        ->and((int) $columns['sale_id']->notnull)->toBe(0)
        ->and($columns['sale_id']->dflt_value)->toBeNull()
        // No key yet: a key onto an existing table is the contract step's.
        ->and(lsmTableKeys($c, 'loyalty_transactions'))->not->toContain('sale_id -> sales.id')
        ->and($c->table('loyalty_transactions')->count())->toBe(3)
        ->and($c->table('loyalty_transactions')->whereNotNull('sale_id')->count())->toBe(0);

    $indexes = collect($c->select('pragma index_list("loyalty_transactions")'))
        ->filter(static fn ($index): bool => (int) $index->unique === 1)
        ->map(static fn ($index): array => array_column($c->select('pragma index_info("'.$index->name.'")'), 'name'))
        ->values()
        ->all();

    expect($indexes)->toContain(['sale_id', 'type']);
});

it('expand: two rows for one (sale_id, type) refuse, a different type for the same sale and any number of NULLs are accepted', function () {
    $c = lsmOpen();
    $sale = lsmId('SALE1');
    lsmSeed($c, [['L1', null, 'earn']]);
    lsmApply(LSM_EXPAND);

    $c->table('sales')->insert([
        'id' => $sale, 'created_at' => LSM_NOW, 'updated_at' => LSM_NOW, 'branch_id' => lsmId('BR1'),
        'location_id' => lsmId('LOC1'), 'employee_id' => lsmId('EMP1'), 'type' => 'sale', 'status' => 'completed',
        'business_date' => '2026-11-10', 'business_date_source' => 'server', 'currency' => 'USD', 'subtotal' => '0.0000',
        'discount_total' => '0.0000', 'tax_total' => '0.0000', 'service_charge' => '0.0000', 'rounding' => '0.0000',
        'total' => '0.0000', 'paid_total' => '0.0000', 'change_due' => '0.0000',
    ]);

    $loyalty = static fn (string $id, ?string $saleId, string $type): array => [
        'id' => lsmId($id), 'customer_id' => lsmId('CUST1'), 'program_id' => lsmId('PROG1'), 'type' => $type,
        'points' => '1.0000', 'balance_after' => '1.0000', 'created_at' => LSM_NOW, 'sale_id' => $saleId,
    ];

    $c->table('loyalty_transactions')->insert($loyalty('A1', $sale, 'earn'));

    expect(fn () => $c->table('loyalty_transactions')->insert($loyalty('A2', $sale, 'earn')))->toThrow(QueryException::class);

    $c->table('loyalty_transactions')->insert($loyalty('A3', $sale, 'redeem'));
    $c->table('loyalty_transactions')->insert($loyalty('A4', null, 'earn'));
    $c->table('loyalty_transactions')->insert($loyalty('A5', null, 'earn'));

    expect($c->table('loyalty_transactions')->count())->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Contract
|--------------------------------------------------------------------------
*/

it('contract: refuses a sale_id naming no sale, naming the table, the column and a COUNT, never an id, and changes nothing', function () {
    $c = lsmOpen();
    lsmApply(LSM_EXPAND);
    lsmSeed($c, [['L1', lsmId('SALE1'), 'earn'], ['L2', LSM_SENTINEL, 'earn'], ['L3', LSM_SENTINEL, 'redeem']]);

    $before = lsmSnapshot($c);
    $message = lsmRefusal(LSM_CONTRACT);

    expect($message)->not->toBeNull()
        ->and($message)->toContain('loyalty_transactions.sale_id')
        ->and($message)->toContain('2 row(s)')
        ->and($message)->not->toContain(LSM_SENTINEL)
        ->and(lsmSnapshot($c))->toBe($before)
        ->and(lsmTableKeys($c, 'loyalty_transactions'))->not->toContain('sale_id -> sales.id');

    // One dirty row reads "1 row(s)".
    $c->table('loyalty_transactions')->where('id', lsmId('L3'))->update(['sale_id' => null]);

    expect(lsmRefusal(LSM_CONTRACT))->toContain('1 row(s)');
});

it('contract: when clean it adds the one key, keeps every row and the unique key through the rebuild, and enforces the key', function () {
    $c = lsmOpen();
    lsmApply(LSM_EXPAND);
    lsmSeed($c, [['L1', lsmId('SALE1'), 'earn'], ['L2', null, 'earn'], ['L3', null, 'earn']]);

    expect(lsmApply(LSM_CONTRACT))->toBe(0);

    expect(lsmTableKeys($c, 'loyalty_transactions'))->toContain('sale_id -> sales.id')
        ->and($c->table('loyalty_transactions')->count())->toBe(3);

    // The rebuild kept the unique key.
    expect(fn () => $c->table('loyalty_transactions')->insert([
        'id' => lsmId('DUP1'), 'customer_id' => lsmId('CUST1'), 'program_id' => lsmId('PROG1'), 'type' => 'earn',
        'points' => '1.0000', 'balance_after' => '1.0000', 'created_at' => LSM_NOW, 'sale_id' => lsmId('SALE1'),
    ]))->toThrow(QueryException::class);

    // The key is enforced: a missing sale is refused, null is accepted.
    expect(fn () => $c->table('loyalty_transactions')->where('id', lsmId('L2'))->update(['sale_id' => LSM_SENTINEL]))
        ->toThrow(QueryException::class);

    $c->table('loyalty_transactions')->where('id', lsmId('L2'))->update(['sale_id' => null]);

    expect($c->table('loyalty_transactions')->whereNull('sale_id')->count())->toBe(2);
});

it('contract: a re-run is a no-op and leaves exactly one key on the column', function () {
    $c = lsmOpen();
    lsmApply(LSM_EXPAND);
    lsmSeed($c, [['L1', lsmId('SALE1'), 'earn']]);
    lsmApply(LSM_CONTRACT);

    $before = lsmSnapshot($c);

    lsmRunUpAgain(LSM_CONTRACT);

    expect(lsmSnapshot($c))->toBe($before)
        ->and(array_count_values(lsmTableKeys($c, 'loyalty_transactions'))['sale_id -> sales.id'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The catalogue rows and the statics
|--------------------------------------------------------------------------
*/

it('catalogues the pair as expand and contract, NEITHER destructive, the contract file being the top of the tree and T-030\'s only one', function () {
    $rows = [];

    foreach ([LSM_EXPAND, LSM_CONTRACT] as $migration) {
        $entry = lsmSet()->entryFor(lsmVersion($migration));

        $rows[$migration] = [$entry->phase, $entry->isDestructive, $entry->requiresOffline];
    }

    expect($rows)->toBe([
        LSM_EXPAND => [MigrationPhase::Expand, false, false],
        LSM_CONTRACT => [MigrationPhase::Contract, false, false],
    ])->and(lsmVersion(LSM_CONTRACT))->toBe(lsmVersion(LSM_EXPAND) + 1)
        ->and(lsmVersion(LSM_CONTRACT))->toBe(lsmSet()->highestVersion());

    // T-014 D-31: one contract file, holding one key. No other T-030 file is a contract step.
    $contracts = [];

    foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_10_0030*.php') ?: [] as $file) {
        if (str_contains((string) file_get_contents($file), '@phase contract')) {
            $contracts[] = basename($file, '.php');
        }
    }

    expect($contracts)->toBe([LSM_CONTRACT]);
});

function lsmCode(string $migration): string
{
    return SourceRules::codeOnly((string) file_get_contents((string) lsmSet()->pathFor(lsmVersion($migration))));
}

it('the expand file holds no foreign key call and no change call; the contract file holds one key and no change call', function () {
    $keyOrChange = '/->\s*(?:foreign|constrained|references|change)\s*\(/';

    expect(preg_match($keyOrChange, lsmCode(LSM_EXPAND)))->toBe(0)
        ->and(preg_match('/->\s*change\s*\(/', lsmCode(LSM_CONTRACT)))->toBe(0)
        ->and(preg_match_all('/->\s*foreign\s*\(/', lsmCode(LSM_CONTRACT)))->toBe(1);

    // Control: the pattern bites on the one spelling a NOT NULL tighten would use.
    expect(preg_match('/->\s*change\s*\(/', "<?php\n\$table->ulid('sale_id')->nullable(false)->change();"))->toBe(1);
});

it('reports no SourceRules violation across the pair', function () {
    $violations = [];

    foreach ([LSM_EXPAND, LSM_CONTRACT] as $migration) {
        $violations = array_merge($violations, SourceRules::inspect((string) file_get_contents((string) lsmSet()->pathFor(lsmVersion($migration))), MigrationTree::Tenant));
    }

    expect($violations)->toBe([]);
});
