<?php

use Flexipos\Kernel\Schema\Support\MigrationPhase;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| The owed account columns: three expand files and one contract file
| (T-032 plan step 17, AC-52, AC-53, AC-54; D-21)
|--------------------------------------------------------------------------
|
| The same shape as ProductTaxGroupMigrationsTest: the files before the first
| expand step are applied once to an in-memory database written out with
| VACUUM INTO, every case copies that file, and the step under test runs
| through the migrator (which is the subject). Each version is found by name,
| never written as a literal.
|
| - the three expand files add exactly five nullable, key-less, default-less
|   CHAR(26) columns and lose nothing (AC-52), and the five-column counter is
|   scoped to the three tables so a later task's tables cannot redden it;
| - the contract file refuses an orphan naming table, column and a COUNT, never
|   a value, and adds no key when it refuses; when clean it adds five keys,
|   keeps every row, the sku/plu_code uniques and every older key through the
|   SQLite table rebuild, and is a no-op when re-run (AC-53);
| - every T-032 catalogue row is not destructive and no file tightens a column
|   to NOT NULL (AC-54); mutation S1 (the contract written as a change) is
|   proven by planting one.
|
| No engine: SQLite files under storage/app, the driver asserted at open.
*/

const OACM_PRODUCTS = '2026_11_15_003205_add_account_columns_to_products_table';

const OACM_CATEGORIES = '2026_11_15_003206_add_income_account_column_to_categories_table';

const OACM_CUSTOMERS = '2026_11_15_003207_add_receivable_account_column_to_customers_table';

const OACM_CONTRACT = '2026_11_15_003208_add_owed_account_foreign_keys';

const OACM_CONNECTION = 'oacm_case';

const OACM_NOW = '2026-11-15 09:00:00.000';

/** A 26-character id no account holds. */
const OACM_SENTINEL = 'ZZLEAKZZ7Q9W3E5R1T8Y6U4I2O';

/** Table, column, and the key spelling the contract file must add. */
const OACM_COLUMNS = [
    ['products', 'income_account_id'],
    ['products', 'cogs_account_id'],
    ['products', 'inventory_account_id'],
    ['categories', 'income_account_id'],
    ['customers', 'receivable_account_id'],
];

function oacmSet(): MigrationSet
{
    return MigrationSet::baseline(migrationTreeDirectory(MigrationTree::Tenant));
}

function oacmVersion(string $migration): int
{
    $version = oacmSet()->versionOf($migration);

    if ($version === null) {
        throw new RuntimeException('The shipped tenant tree does not hold '.$migration.'.');
    }

    return $version;
}

function oacmTemplatePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'oacm-template.sqlite');
}

function oacmCasePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'oacm-case.sqlite');
}

/**
 * Every file before the first expand step, in one migrate call, into memory,
 * then out to a file.
 */
function oacmBuildTemplate(): void
{
    $name = 'oacm_template_memory';

    config()->set('database.connections.'.$name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    expect(config('database.connections.'.$name.'.driver'))->toBe('sqlite');

    $paths = array_slice(oacmSet()->paths(), 0, oacmVersion(OACM_PRODUCTS) - 1);

    expect(Artisan::call('migrate', ['--database' => $name, '--path' => $paths, '--realpath' => true, '--force' => true]))->toBe(0);

    File::delete(oacmTemplatePath());

    DB::connection($name)->statement('vacuum into '.DB::connection($name)->getPdo()->quote(oacmTemplatePath()));

    DB::purge($name);
}

/**
 * A fresh copy of the before-expand database, with keys enforced.
 */
function oacmOpen(): Connection
{
    DB::purge(OACM_CONNECTION);

    File::delete(oacmCasePath());
    File::copy(oacmTemplatePath(), oacmCasePath());

    config()->set('database.connections.'.OACM_CONNECTION, [
        'driver' => 'sqlite',
        'database' => oacmCasePath(),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.'.OACM_CONNECTION.'.driver'))->toBe('sqlite');

    /** @var Connection $connection */
    $connection = DB::connection(OACM_CONNECTION);

    $connection->statement('pragma synchronous = off');

    return $connection;
}

function oacmApply(string $migration): int
{
    return Artisan::call('migrate', [
        '--database' => OACM_CONNECTION,
        '--path' => [oacmSet()->pathFor(oacmVersion($migration))],
        '--realpath' => true,
        '--force' => true,
    ]);
}

function oacmApplyExpand(): void
{
    foreach ([OACM_PRODUCTS, OACM_CATEGORIES, OACM_CUSTOMERS] as $migration) {
        expect(oacmApply($migration))->toBe(0);
    }
}

/**
 * Call one migration's `up()` a second time directly: the migrator would skip
 * a recorded file, and "re-run" is the thing under test.
 */
function oacmRunUpAgain(string $migration): void
{
    $migrationObject = require (string) oacmSet()->pathFor(oacmVersion($migration));
    $previous = DB::getDefaultConnection();

    DB::setDefaultConnection(OACM_CONNECTION);

    try {
        $migrationObject->up();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function oacmRefusal(string $migration): ?string
{
    try {
        oacmApply($migration);
    } catch (Throwable $thrown) {
        return $thrown->getMessage();
    }

    return null;
}

function oacmId(string $stem): string
{
    return strtoupper(str_pad(substr($stem, 0, 26), 26, '0'));
}

/**
 * One account (a postable revenue leaf) the keys can point at.
 */
function oacmSeedAccount(Connection $c, string $stem = 'ACC1'): string
{
    $id = oacmId($stem);

    $c->table('accounts')->insert([
        'id' => $id, 'created_at' => OACM_NOW, 'updated_at' => OACM_NOW,
        'code' => $stem, 'name' => 'Account '.$stem, 'type' => 'revenue', 'path' => $id, 'depth' => 0,
        'normal_balance' => 'credit',
    ]);

    return $id;
}

/**
 * A unit and a category, then one product per SKU stem; and one customer.
 *
 * @param  list<string>  $stems
 * @return list<string> the product ids
 */
function oacmSeedRows(Connection $c, array $stems): array
{
    $unit = oacmId('UNIT1');
    $category = oacmId('CAT1');

    if (! $c->table('units')->where('id', $unit)->exists()) {
        $c->table('units')->insert([
            'id' => $unit, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count', 'precision' => 0,
            'created_at' => OACM_NOW, 'updated_at' => OACM_NOW,
        ]);
        $c->table('categories')->insert([
            'id' => $category, 'created_at' => OACM_NOW, 'updated_at' => OACM_NOW,
            'code' => 'c1', 'name' => 'Cat 1', 'path' => $category, 'depth' => 0,
        ]);
        $c->table('customers')->insert([
            'id' => oacmId('CUST1'), 'created_at' => OACM_NOW, 'updated_at' => OACM_NOW, 'name' => 'Customer 1',
        ]);
    }

    $ids = [];

    foreach ($stems as $stem) {
        $id = oacmId('PROD'.$stem);
        $c->table('products')->insert([
            'id' => $id, 'created_at' => OACM_NOW, 'updated_at' => OACM_NOW,
            'type' => 'simple', 'sku' => 'sku-'.$stem, 'name' => 'Product '.$stem,
            'category_id' => $category, 'stock_unit_id' => $unit,
        ]);
        $ids[] = $id;
    }

    return $ids;
}

/**
 * The foreign keys of one table as `from -> table.to`, sorted.
 *
 * @return list<string>
 */
function oacmTableKeys(Connection $c, string $table): array
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
 * Every row and the stored DDL of the three tables, and any temp table a
 * half-finished rebuild left behind.
 *
 * @return array<string, string>
 */
function oacmSnapshot(Connection $c): array
{
    $snapshot = [];

    foreach (['products', 'categories', 'customers'] as $table) {
        $snapshot[$table.' rows'] = json_encode($c->select('select * from "'.$table.'" order by id'), JSON_THROW_ON_ERROR);
        $snapshot[$table.' ddl'] = (string) ($c->selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table])->sql ?? '');
    }

    $snapshot['temp tables'] = json_encode(array_column(
        $c->select("select name from sqlite_master where name like '%__temp__%' order by name"),
        'name'
    ), JSON_THROW_ON_ERROR);

    return $snapshot;
}

/**
 * Every column of one table that ends in `_account_id`.
 *
 * @return list<string>
 */
function oacmAccountColumns(Connection $c, string $table): array
{
    $names = array_column($c->select('pragma table_info("'.$table.'")'), 'name');

    return array_values(array_filter($names, static fn (string $name): bool => str_ends_with($name, '_account_id')));
}

beforeEach(function () {
    oacmBuildTemplate();
});

afterEach(function () {
    DB::purge(OACM_CONNECTION);

    File::delete(oacmCasePath());
    File::delete(oacmTemplatePath());
});

/*
|--------------------------------------------------------------------------
| Expand (AC-52)
|--------------------------------------------------------------------------
*/

it('expand: adds exactly five nullable CHAR(26) columns with no default and no key, and every row survives (AC-52)', function () {
    $c = oacmOpen();
    $ids = oacmSeedRows($c, ['A', 'B']);

    foreach (['products', 'categories', 'customers'] as $table) {
        expect(oacmAccountColumns($c, $table))->toBe([]);
    }

    oacmApplyExpand();

    // The stand-in reports a type without its length, so the type is compared with a column
    // IdentifierColumn already built (accounts.parent_id); the width is pinned by the source below.
    $reference = collect($c->select('pragma table_info("accounts")'))->keyBy('name')['parent_id'];

    foreach (OACM_COLUMNS as [$table, $column]) {
        $info = collect($c->select('pragma table_info("'.$table.'")'))->keyBy('name')[$column];

        expect((int) $info->notnull)->toBe(0)
            ->and($info->dflt_value)->toBeNull()
            ->and($info->type)->toBe($reference->type);
    }

    $declared = '';

    foreach ([OACM_PRODUCTS, OACM_CATEGORIES, OACM_CUSTOMERS] as $migration) {
        $declared .= oacmCode((string) oacmSet()->pathFor(oacmVersion($migration)));
    }

    // Five columns, each through IdentifierColumn::nullable (CHAR(26), the identifier collation), nothing else.
    expect(preg_match_all('/IdentifierColumn::nullable\(\$table, \'\w+_account_id\'\)/', $declared))->toBe(5)
        ->and(preg_match_all('/\$table->/', $declared))->toBe(0);

    // F-5's counter, scoped to the three tables: exactly five, and no categories cogs column.
    expect(oacmAccountColumns($c, 'products'))->toBe(['income_account_id', 'cogs_account_id', 'inventory_account_id'])
        ->and(oacmAccountColumns($c, 'categories'))->toBe(['income_account_id'])
        ->and(oacmAccountColumns($c, 'customers'))->toBe(['receivable_account_id'])
        ->and(count(oacmAccountColumns($c, 'products')) + count(oacmAccountColumns($c, 'categories')) + count(oacmAccountColumns($c, 'customers')))->toBe(5);

    // No key yet: a key onto an existing table is the contract step's.
    foreach (['products', 'categories', 'customers'] as $table) {
        expect(implode('|', oacmTableKeys($c, $table)))->not->toContain('accounts');
    }

    expect($c->table('products')->orderBy('id')->pluck('id')->all())->toBe(collect($ids)->sort()->values()->all())
        ->and($c->table('products')->whereNotNull('income_account_id')->count())->toBe(0)
        ->and($c->table('customers')->whereNotNull('receivable_account_id')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Contract (AC-53)
|--------------------------------------------------------------------------
*/

it('contract: refuses an orphan naming the column and a COUNT, never a value, and adds no key (AC-53 red-first)', function () {
    $c = oacmOpen();
    oacmApplyExpand();
    $ids = oacmSeedRows($c, ['A', 'B']);

    $c->table('products')->where('id', $ids[0])->update(['income_account_id' => OACM_SENTINEL]);

    $before = oacmSnapshot($c);
    $message = oacmRefusal(OACM_CONTRACT);

    expect($message)->not->toBeNull()
        ->and($message)->toContain('products.income_account_id')
        ->and($message)->toContain('1 row(s)')
        ->and($message)->not->toContain('products.cogs_account_id')
        ->and($message)->not->toContain(OACM_SENTINEL)
        ->and(oacmSnapshot($c))->toBe($before);

    foreach (['products', 'categories', 'customers'] as $table) {
        expect(implode('|', oacmTableKeys($c, $table)))->not->toContain('accounts');
    }

    // Every dirty column is named in the one message, with its own count.
    $c->table('products')->where('id', $ids[1])->update(['income_account_id' => OACM_SENTINEL, 'cogs_account_id' => OACM_SENTINEL]);
    $c->table('customers')->update(['receivable_account_id' => OACM_SENTINEL]);

    $all = oacmRefusal(OACM_CONTRACT);

    expect($all)->toContain('products.income_account_id: 2 row(s)')
        ->and($all)->toContain('products.cogs_account_id: 1 row(s)')
        ->and($all)->toContain('customers.receivable_account_id: 1 row(s)')
        ->and($all)->not->toContain(OACM_SENTINEL);

    // A lower-case spelling of a real id is also an orphan: the comparison is exact.
    $c->table('products')->update(['income_account_id' => null, 'cogs_account_id' => null]);
    $c->table('customers')->update(['receivable_account_id' => null]);
    $account = oacmSeedAccount($c);
    $c->table('categories')->update(['income_account_id' => strtolower($account)]);

    expect(oacmRefusal(OACM_CONTRACT))->toContain('categories.income_account_id: 1 row(s)');
});

it('contract: when clean it adds five keys, keeps every row, the sku and plu_code uniques and every older key, and enforces them (AC-53)', function () {
    $c = oacmOpen();
    oacmApplyExpand();
    $ids = oacmSeedRows($c, ['A', 'B', 'C']);
    $c->table('products')->where('id', $ids[0])->update(['plu_code' => '1001']);

    $account = oacmSeedAccount($c);
    $c->table('products')->where('id', $ids[0])->update(['income_account_id' => $account]);
    $c->table('categories')->update(['income_account_id' => $account]);
    $c->table('customers')->update(['receivable_account_id' => $account]);

    $keysBefore = [];
    $rowsBefore = [];

    foreach (['products', 'categories', 'customers'] as $table) {
        $keysBefore[$table] = oacmTableKeys($c, $table);
        $rowsBefore[$table] = $c->table($table)->count();
    }

    expect(oacmApply(OACM_CONTRACT))->toBe(0);

    foreach (OACM_COLUMNS as [$table, $column]) {
        expect(oacmTableKeys($c, $table))->toContain($column.' -> accounts.id');
    }

    foreach (['products', 'categories', 'customers'] as $table) {
        $added = count(array_filter(OACM_COLUMNS, static fn (array $pair): bool => $pair[0] === $table));

        // Every pre-existing key survived the rebuild, and exactly the new ones were added.
        expect(array_values(array_diff(oacmTableKeys($c, $table), $keysBefore[$table])))->toHaveCount($added)
            ->and(array_values(array_diff($keysBefore[$table], oacmTableKeys($c, $table))))->toBe([])
            ->and($c->table($table)->count())->toBe($rowsBefore[$table]);
    }

    expect($c->table('products')->where('id', $ids[0])->value('income_account_id'))->toBe($account);

    // The rebuild kept the unique keys.
    $dup = static fn (string $id, string $sku, ?string $plu): array => [
        'id' => oacmId($id), 'created_at' => OACM_NOW, 'updated_at' => OACM_NOW, 'type' => 'simple',
        'sku' => $sku, 'name' => 'Dup', 'category_id' => oacmId('CAT1'), 'stock_unit_id' => oacmId('UNIT1'), 'plu_code' => $plu,
    ];

    expect(fn () => $c->table('products')->insert($dup('DUP1', 'sku-A', null)))->toThrow(QueryException::class)
        ->and(fn () => $c->table('products')->insert($dup('DUP2', 'sku-new', '1001')))->toThrow(QueryException::class);

    // The keys are enforced on every column, and null is accepted.
    foreach (OACM_COLUMNS as [$table, $column]) {
        expect(fn () => $c->table($table)->limit(1)->update([$column => OACM_SENTINEL]))->toThrow(QueryException::class);
    }

    $c->table('products')->where('id', $ids[1])->update(['cogs_account_id' => null, 'inventory_account_id' => $account]);

    expect($c->table('products')->where('id', $ids[1])->value('inventory_account_id'))->toBe($account);
});

it('contract: a re-run is a no-op and leaves exactly one key per column (AC-53)', function () {
    $c = oacmOpen();
    oacmApplyExpand();
    oacmSeedRows($c, ['A']);
    oacmApply(OACM_CONTRACT);

    $before = oacmSnapshot($c);

    oacmRunUpAgain(OACM_CONTRACT);

    expect(oacmSnapshot($c))->toBe($before);

    foreach (OACM_COLUMNS as [$table, $column]) {
        expect(array_count_values(oacmTableKeys($c, $table))[$column.' -> accounts.id'])->toBe(1);
    }
});

/*
|--------------------------------------------------------------------------
| The catalogue rows and the statics (AC-54, mutation S1)
|--------------------------------------------------------------------------
*/

/**
 * The code of one migration file, comments and docblocks removed.
 */
function oacmCode(string $file): string
{
    return SourceRules::codeOnly((string) file_get_contents($file));
}

/**
 * Every statement that changes or tightens an existing column, in a code
 * string: a change call, or a nullable(false) spelling.
 */
function oacmTightens(string $code): int
{
    return preg_match_all('/->\s*change\s*\(|->\s*nullable\s*\(\s*false\s*\)|\balter\s+table\b[^;\'"]*\b(?:modify|change)\b/i', $code);
}

/**
 * The T-032 migration files, found by their shared date and task prefix.
 *
 * @return list<string>
 */
function oacmT032Files(): array
{
    $directory = migrationTreeDirectory(MigrationTree::Tenant);

    return glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'2026_11_15_0032*.php') ?: [];
}

it('AC-54: every T-032 catalogue row is not destructive, the three expand files are expand and the contract file is contract', function () {
    $files = oacmT032Files();

    expect($files)->toHaveCount(8);

    foreach ($files as $file) {
        $entry = oacmSet()->entryFor(oacmVersion(basename($file, '.php')));

        expect($entry->isDestructive)->toBeFalse(basename($file))
            ->and($entry->requiresOffline)->toBeFalse(basename($file));
    }

    foreach ([OACM_PRODUCTS, OACM_CATEGORIES, OACM_CUSTOMERS] as $migration) {
        expect(oacmSet()->entryFor(oacmVersion($migration))->phase)->toBe(MigrationPhase::Expand);
    }

    expect(oacmSet()->entryFor(oacmVersion(OACM_CONTRACT))->phase)->toBe(MigrationPhase::Contract)
        // One contract file after the three expand files: a fleet needs two runs, not five.
        ->and(oacmVersion(OACM_CONTRACT))->toBe(oacmVersion(OACM_CUSTOMERS) + 1);
});

it('AC-54 and S1: no T-032 file changes or tightens a column, and a contract written as a change is reported', function () {
    foreach (oacmT032Files() as $file) {
        expect(oacmTightens(oacmCode($file)))->toBe(0, basename($file));
    }

    // Mutation S1: the contract file rewritten with a change call is caught by the same scan.
    $real = oacmCode((string) oacmSet()->pathFor(oacmVersion(OACM_CONTRACT)));
    $mutated = str_replace('->references(\'id\')->on(\'accounts\');', '->references(\'id\')->on(\'accounts\'); $blueprint->string(\'x\')->nullable(false)->change();', $real);

    expect($mutated)->not->toBe($real)
        ->and(oacmTightens($mutated))->toBeGreaterThan(0)
        ->and(oacmTightens("<?php\n\$table->char('income_account_id', 26)->change();"))->toBe(1);
});

it('AC-52: the expand files hold no key call and no change call, and the contract file holds the keys', function () {
    $keyOrChange = '/->\s*(?:foreign|constrained|references|change)\s*\(/';

    foreach ([OACM_PRODUCTS, OACM_CATEGORIES, OACM_CUSTOMERS] as $migration) {
        expect(preg_match($keyOrChange, oacmCode((string) oacmSet()->pathFor(oacmVersion($migration)))))->toBe(0, $migration);
    }

    // Control: the contract file legitimately holds a key call, so the same scan reports it.
    expect(preg_match($keyOrChange, oacmCode((string) oacmSet()->pathFor(oacmVersion(OACM_CONTRACT)))))->toBe(1);
});
