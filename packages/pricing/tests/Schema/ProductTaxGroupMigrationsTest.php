<?php

namespace Flexipos\Pricing\Tests\Schema;

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
| The products trio: expand, backfill, contract (T-029 plan steps 3 and 4,
| AC-1, AC-2, AC-29; D-5, D-6, D-14)
|--------------------------------------------------------------------------
|
| The same shape as BranchForeignKeysMigrationTest: the files before the
| expand step are applied once to an in-memory database written out with
| VACUUM INTO, every case copies that file, and the step under test runs
| through the migrator (which is the subject). Nothing here writes a
| catalogue version as a literal: each version is found by name.
|
| What this file proves, in the order the plan lists it:
|
| - expand adds two nullable, key-less columns and loses nothing;
| - the backfill seeds one STANDARD default when there is none, uses an
|   existing default when there is one, never touches a group a product
|   already holds, never touches purchase_tax_group_id, and is a no-op when
|   re-run;
| - the contract file refuses dirty data naming a count and never an id,
|   changes nothing when it refuses, adds both keys when clean, survives the
|   SQLite table rebuild with rows and unique keys intact, and is a no-op when
|   re-run;
| - all three catalogue rows are NOT destructive (F-3's proof, the reason the
|   NOT NULL tighten is not shipped);
| - AC-29's three static halves, each with a planted control.
|
| No engine: SQLite files under storage/app, the driver asserted at open.
*/

const PTGM_EXPAND = '2026_11_10_002906_add_tax_group_columns_to_products_table';

const PTGM_BACKFILL = '2026_11_10_002907_backfill_product_tax_groups';

const PTGM_CONTRACT = '2026_11_10_002912_add_product_tax_group_foreign_keys';

const PTGM_CONNECTION = 'ptgm_case';

const PTGM_NOW = '2026-11-10 09:00:00.000';

/** A 26-character id no row of any parent table holds. */
const PTGM_SENTINEL = 'ZZLEAKZZ7Q9W3E5R1T8Y6U4I2O';

function ptgmSet(): MigrationSet
{
    return MigrationSet::baseline(migrationTreeDirectory(MigrationTree::Tenant));
}

function ptgmVersion(string $migration): int
{
    $version = ptgmSet()->versionOf($migration);

    if ($version === null) {
        throw new \RuntimeException('The shipped tenant tree does not hold '.$migration.'.');
    }

    return $version;
}

function ptgmTemplatePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'ptgm-template.sqlite');
}

function ptgmCasePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'ptgm-case.sqlite');
}

/**
 * Every file before the expand step, in one migrate call, into memory, then
 * out to a file.
 */
function ptgmBuildTemplate(): void
{
    $name = 'ptgm_template_memory';

    config()->set('database.connections.'.$name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    expect(config('database.connections.'.$name.'.driver'))->toBe('sqlite');

    $paths = array_slice(ptgmSet()->paths(), 0, ptgmVersion(PTGM_EXPAND) - 1);

    expect(Artisan::call('migrate', ['--database' => $name, '--path' => $paths, '--realpath' => true, '--force' => true]))->toBe(0);

    File::delete(ptgmTemplatePath());

    DB::connection($name)->statement('vacuum into '.DB::connection($name)->getPdo()->quote(ptgmTemplatePath()));

    DB::purge($name);
}

/**
 * A fresh copy of the before-expand database, with keys enforced.
 */
function ptgmOpen(): Connection
{
    DB::purge(PTGM_CONNECTION);

    File::delete(ptgmCasePath());
    File::copy(ptgmTemplatePath(), ptgmCasePath());

    config()->set('database.connections.'.PTGM_CONNECTION, [
        'driver' => 'sqlite',
        'database' => ptgmCasePath(),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.'.PTGM_CONNECTION.'.driver'))->toBe('sqlite');

    /** @var Connection $connection */
    $connection = DB::connection(PTGM_CONNECTION);

    $connection->statement('pragma synchronous = off');

    return $connection;
}

/**
 * Run one named migration through the migrator, as the runner's applier does.
 */
function ptgmApply(string $migration): int
{
    return Artisan::call('migrate', [
        '--database' => PTGM_CONNECTION,
        '--path' => [ptgmSet()->pathFor(ptgmVersion($migration))],
        '--realpath' => true,
        '--force' => true,
    ]);
}

/**
 * Call one migration's `up()` a second time directly, on the open case: the
 * migrator would skip a recorded file, and "re-run" is the thing under test.
 */
function ptgmRunUpAgain(string $migration): void
{
    $migrationObject = require (string) ptgmSet()->pathFor(ptgmVersion($migration));
    $previous = DB::getDefaultConnection();

    DB::setDefaultConnection(PTGM_CONNECTION);

    try {
        $migrationObject->up();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/**
 * The message a refused run threw, or null when it ran.
 */
function ptgmRefusal(string $migration): ?string
{
    try {
        ptgmApply($migration);
    } catch (\Throwable $thrown) {
        return $thrown->getMessage();
    }

    return null;
}

function ptgmId(string $stem): string
{
    return strtoupper(str_pad(substr($stem, 0, 26), 26, '0'));
}

/**
 * A unit and a category, then $count products with the given SKU stems.
 *
 * @param  list<string>  $stems
 * @return list<string> the product ids
 */
function ptgmSeedProducts(Connection $c, array $stems): array
{
    $unit = ptgmId('UNIT1');
    $category = ptgmId('CAT1');

    if (! $c->table('units')->where('id', $unit)->exists()) {
        $c->table('units')->insert([
            'id' => $unit, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count', 'precision' => 0,
            'created_at' => PTGM_NOW, 'updated_at' => PTGM_NOW,
        ]);
        $c->table('categories')->insert([
            'id' => $category, 'created_at' => PTGM_NOW, 'updated_at' => PTGM_NOW,
            'code' => 'c1', 'name' => 'Cat 1', 'path' => $category, 'depth' => 0,
        ]);
    }

    $ids = [];

    foreach ($stems as $stem) {
        $id = ptgmId('PROD'.$stem);
        $c->table('products')->insert([
            'id' => $id, 'created_at' => PTGM_NOW, 'updated_at' => PTGM_NOW,
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
function ptgmTableKeys(Connection $c, string $table): array
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
 * Every row and the stored DDL of the products table, and any temp table a
 * half-finished rebuild left behind.
 *
 * @return array<string, string>
 */
function ptgmSnapshot(Connection $c): array
{
    return [
        'products rows' => json_encode($c->select('select * from "products" order by id'), JSON_THROW_ON_ERROR),
        'products ddl' => (string) ($c->selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', 'products'])->sql ?? ''),
        'temp tables' => json_encode(array_column(
            $c->select("select name from sqlite_master where name like '%__temp__%' order by name"),
            'name'
        ), JSON_THROW_ON_ERROR),
    ];
}

beforeEach(function () {
    ptgmBuildTemplate();
});

afterEach(function () {
    DB::purge(PTGM_CONNECTION);

    File::delete(ptgmCasePath());
    File::delete(ptgmTemplatePath());
});

/*
|--------------------------------------------------------------------------
| Expand
|--------------------------------------------------------------------------
*/

it('expand: adds two nullable, key-less columns and every seeded product survives (AC-1, AC-2)', function () {
    $c = ptgmOpen();
    $ids = ptgmSeedProducts($c, ['A', 'B']);

    $before = array_column($c->select('pragma table_info("products")'), 'name');

    expect($before)->not->toContain('tax_group_id')->and($before)->not->toContain('purchase_tax_group_id');

    expect(ptgmApply(PTGM_EXPAND))->toBe(0);

    $columns = collect($c->select('pragma table_info("products")'))->keyBy('name');

    expect($columns)->toHaveKeys(['tax_group_id', 'purchase_tax_group_id'])
        ->and((int) $columns['tax_group_id']->notnull)->toBe(0)
        ->and((int) $columns['purchase_tax_group_id']->notnull)->toBe(0)
        ->and($columns['tax_group_id']->dflt_value)->toBeNull()
        ->and($columns['purchase_tax_group_id']->dflt_value)->toBeNull();

    // No key yet: a key onto an existing table is the contract step's.
    expect(implode('|', ptgmTableKeys($c, 'products')))->not->toContain('tax_group');

    expect($c->table('products')->orderBy('id')->pluck('id')->all())->toBe(collect($ids)->sort()->values()->all())
        ->and($c->table('products')->whereNotNull('tax_group_id')->count())->toBe(0)
        ->and($c->table('products')->whereNotNull('purchase_tax_group_id')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Backfill
|--------------------------------------------------------------------------
*/

it('backfill: with no groups it creates exactly one STANDARD default and sets every product to it (D-6)', function () {
    $c = ptgmOpen();
    ptgmApply(PTGM_EXPAND);
    $ids = ptgmSeedProducts($c, ['A', 'B', 'C']);

    expect($c->table('tax_groups')->count())->toBe(0);

    expect(ptgmApply(PTGM_BACKFILL))->toBe(0);

    $groups = $c->table('tax_groups')->get();

    expect($groups)->toHaveCount(1);

    $group = (array) $groups[0];

    expect($group['code'])->toBe('STANDARD')
        ->and($group['name'])->toBe('Standard')
        ->and((int) $group['is_inclusive'])->toBe(0)
        ->and((int) $group['is_default'])->toBe(1)
        ->and((int) $group['is_active'])->toBe(1)
        ->and(strlen((string) $group['id']))->toBe(26)
        ->and($group['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/');

    expect($c->table('products')->whereIn('id', $ids)->pluck('tax_group_id')->unique()->values()->all())->toBe([$group['id']])
        ->and($c->table('products')->whereNull('tax_group_id')->count())->toBe(0)
        // AC-2: the purchase column is never written.
        ->and($c->table('products')->whereNotNull('purchase_tax_group_id')->count())->toBe(0);
});

it('backfill: with a pre-seeded default it creates no group, uses that one, and never overwrites a group a product holds', function () {
    $c = ptgmOpen();
    ptgmApply(PTGM_EXPAND);
    [$a, $b, $held] = ptgmSeedProducts($c, ['A', 'B', 'H']);

    $default = ptgmId('GRPVAT15');
    $other = ptgmId('GRPOTHER');
    $c->table('tax_groups')->insert([
        ['id' => $default, 'created_at' => PTGM_NOW, 'updated_at' => PTGM_NOW, 'code' => 'VAT15', 'name' => 'VAT 15', 'is_default' => true],
        ['id' => $other, 'created_at' => PTGM_NOW, 'updated_at' => PTGM_NOW, 'code' => 'ZERO', 'name' => 'Zero rated', 'is_default' => false],
    ]);
    $c->table('products')->where('id', $held)->update(['tax_group_id' => $other]);

    expect(ptgmApply(PTGM_BACKFILL))->toBe(0);

    expect($c->table('tax_groups')->count())->toBe(2)
        ->and($c->table('tax_groups')->where('code', 'STANDARD')->exists())->toBeFalse()
        ->and($c->table('products')->where('id', $a)->value('tax_group_id'))->toBe($default)
        ->and($c->table('products')->where('id', $b)->value('tax_group_id'))->toBe($default)
        // A group a product already holds is left alone.
        ->and($c->table('products')->where('id', $held)->value('tax_group_id'))->toBe($other)
        ->and($c->table('products')->whereNotNull('purchase_tax_group_id')->count())->toBe(0);
});

it('backfill: a re-run changes nothing, and with no products it still leaves exactly one default group', function () {
    $c = ptgmOpen();
    ptgmApply(PTGM_EXPAND);
    ptgmSeedProducts($c, ['A', 'B']);
    ptgmApply(PTGM_BACKFILL);

    $groups = json_encode($c->table('tax_groups')->orderBy('id')->get(), JSON_THROW_ON_ERROR);
    $products = json_encode($c->table('products')->orderBy('id')->get(), JSON_THROW_ON_ERROR);

    ptgmRunUpAgain(PTGM_BACKFILL);

    expect(json_encode($c->table('tax_groups')->orderBy('id')->get(), JSON_THROW_ON_ERROR))->toBe($groups)
        ->and(json_encode($c->table('products')->orderBy('id')->get(), JSON_THROW_ON_ERROR))->toBe($products);

    // A tenant with no products at all (a fresh provision) still gets its default.
    $fresh = ptgmOpen();
    ptgmApply(PTGM_EXPAND);
    ptgmApply(PTGM_BACKFILL);

    expect($fresh->table('tax_groups')->where('is_default', true)->count())->toBe(1)
        ->and($fresh->table('tax_groups')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Contract
|--------------------------------------------------------------------------
*/

it('contract: refuses dirty data naming the column and a COUNT, never an id, and changes nothing (F-5)', function () {
    $c = ptgmOpen();
    ptgmApply(PTGM_EXPAND);
    ptgmSeedProducts($c, ['A', 'B']);
    ptgmApply(PTGM_BACKFILL);

    // One product naming a missing sale group.
    $c->table('products')->where('id', ptgmId('PRODA'))->update(['tax_group_id' => PTGM_SENTINEL]);

    $before = ptgmSnapshot($c);
    $message = ptgmRefusal(PTGM_CONTRACT);

    expect($message)->not->toBeNull()
        ->and($message)->toContain('products.tax_group_id')
        ->and($message)->toContain('1 row(s)')
        ->and($message)->not->toContain('products.purchase_tax_group_id')
        ->and($message)->not->toContain(PTGM_SENTINEL)
        ->and(ptgmSnapshot($c))->toBe($before)
        ->and(ptgmTableKeys($c, 'products'))->not->toContain('tax_group_id -> tax_groups.id');

    // Both columns dirty: one message names both, with their own counts.
    $c->table('products')->where('id', ptgmId('PRODB'))->update(['purchase_tax_group_id' => PTGM_SENTINEL]);
    $c->table('products')->where('id', ptgmId('PRODA'))->update(['purchase_tax_group_id' => PTGM_SENTINEL]);

    $both = ptgmRefusal(PTGM_CONTRACT);

    expect($both)->toContain('products.tax_group_id: 1 row(s)')
        ->and($both)->toContain('products.purchase_tax_group_id: 2 row(s)')
        ->and($both)->not->toContain(PTGM_SENTINEL);
});

it('contract: when clean it adds both keys, keeps every row and unique key through the rebuild, and enforces the keys', function () {
    $c = ptgmOpen();
    ptgmApply(PTGM_EXPAND);
    $ids = ptgmSeedProducts($c, ['A', 'B', 'C']);
    $c->table('products')->where('id', $ids[0])->update(['plu_code' => '1001']);
    ptgmApply(PTGM_BACKFILL);

    $default = $c->table('tax_groups')->where('is_default', true)->value('id');
    $rowsBefore = $c->table('products')->orderBy('id')->count();

    expect(ptgmApply(PTGM_CONTRACT))->toBe(0);

    expect(ptgmTableKeys($c, 'products'))->toContain('tax_group_id -> tax_groups.id')
        ->and(ptgmTableKeys($c, 'products'))->toContain('purchase_tax_group_id -> tax_groups.id')
        ->and($c->table('products')->count())->toBe($rowsBefore)
        ->and($c->table('products')->whereIn('id', $ids)->pluck('tax_group_id')->unique()->values()->all())->toBe([$default]);

    // The rebuild kept the unique keys (risk 6).
    $dup = static fn (string $id, string $sku, ?string $plu): array => [
        'id' => ptgmId($id), 'created_at' => PTGM_NOW, 'updated_at' => PTGM_NOW, 'type' => 'simple',
        'sku' => $sku, 'name' => 'Dup', 'category_id' => ptgmId('CAT1'), 'stock_unit_id' => ptgmId('UNIT1'), 'plu_code' => $plu,
    ];

    expect(fn () => $c->table('products')->insert($dup('DUP1', 'sku-A', null)))->toThrow(QueryException::class)
        ->and(fn () => $c->table('products')->insert($dup('DUP2', 'sku-new', '1001')))->toThrow(QueryException::class);

    // The keys are enforced: a missing group is refused on either column, null is accepted.
    expect(fn () => $c->table('products')->where('id', $ids[1])->update(['tax_group_id' => PTGM_SENTINEL]))->toThrow(QueryException::class)
        ->and(fn () => $c->table('products')->where('id', $ids[1])->update(['purchase_tax_group_id' => PTGM_SENTINEL]))->toThrow(QueryException::class);

    $c->table('products')->where('id', $ids[1])->update(['purchase_tax_group_id' => $default]);
    $c->table('products')->where('id', $ids[2])->update(['purchase_tax_group_id' => null]);

    expect($c->table('products')->where('id', $ids[1])->value('purchase_tax_group_id'))->toBe($default);
});

it('contract: a re-run is a no-op and leaves exactly one key per column', function () {
    $c = ptgmOpen();
    ptgmApply(PTGM_EXPAND);
    ptgmSeedProducts($c, ['A']);
    ptgmApply(PTGM_BACKFILL);
    ptgmApply(PTGM_CONTRACT);

    $before = ptgmSnapshot($c);

    ptgmRunUpAgain(PTGM_CONTRACT);

    $keys = ptgmTableKeys($c, 'products');

    expect(ptgmSnapshot($c))->toBe($before)
        ->and(array_count_values($keys)['tax_group_id -> tax_groups.id'])->toBe(1)
        ->and(array_count_values($keys)['purchase_tax_group_id -> tax_groups.id'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The catalogue rows (F-3's proof) and the statics (AC-29)
|--------------------------------------------------------------------------
*/

it('catalogues the trio as expand, backfill and contract, and NONE of them as destructive (F-3, D-14)', function () {
    $rows = [];

    foreach ([PTGM_EXPAND, PTGM_BACKFILL, PTGM_CONTRACT] as $migration) {
        $entry = ptgmSet()->entryFor(ptgmVersion($migration));

        $rows[$migration] = [$entry->phase, $entry->isDestructive, $entry->requiresOffline];
    }

    expect($rows)->toBe([
        PTGM_EXPAND => [MigrationPhase::Expand, false, false],
        PTGM_BACKFILL => [MigrationPhase::Backfill, false, false],
        PTGM_CONTRACT => [MigrationPhase::Contract, false, false],
    ]);

    // The contract file is the last of the twelve: a fleet below the version before it runs in two steps.
    expect(ptgmVersion(PTGM_CONTRACT))->toBe(ptgmVersion(PTGM_EXPAND) + 6);
});

/**
 * The code of one migration file, comments and docblocks removed.
 */
function ptgmCode(string $migration): string
{
    return SourceRules::codeOnly((string) file_get_contents((string) ptgmSet()->pathFor(ptgmVersion($migration))));
}

/**
 * Foreign-key spellings and `change` calls in a code string.
 *
 * @return list<string>
 */
function ptgmKeyOrChangeCalls(string $code): array
{
    preg_match_all('/->\s*(?:foreign|constrained|references|change)\s*\(/', $code, $matches);

    return $matches[0];
}

it('AC-29.1: the file that adds the columns contains no foreign key call and no change call', function () {
    expect(ptgmKeyOrChangeCalls(ptgmCode(PTGM_EXPAND)))->toBe([]);

    // Control: the contract file legitimately holds a key call, so the same scan reports it.
    expect(ptgmKeyOrChangeCalls(ptgmCode(PTGM_CONTRACT)))->not->toBe([]);

    expect(ptgmKeyOrChangeCalls("<?php\n\$table->ulid('tax_group_id')->nullable()->change();"))->toBe(['->change(']);
});

it('AC-29.2: the contract file contains no change call, so it is additive only', function () {
    expect(preg_match('/->\s*change\s*\(/', ptgmCode(PTGM_CONTRACT)))->toBe(0)
        ->and(preg_match('/\balter\s+table\b[^;\'"]*\b(?:modify|change)\b/i', ptgmCode(PTGM_CONTRACT)))->toBe(0);

    // Control: the pattern bites on the one spelling a NOT NULL tighten would use.
    expect(preg_match('/->\s*change\s*\(/', "<?php\n\$table->ulid('tax_group_id')->nullable(false)->change();"))->toBe(1);
});

/**
 * Every offence AC-29.3 names, per file, over a directory's migrations: a key
 * onto `accounts`, or a declaration of one of the three deferred columns.
 *
 * @return list<string>
 */
function ptgmAccountOffenders(string $directory): array
{
    $found = [];

    foreach (glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
        $code = SourceRules::codeOnly((string) file_get_contents($file));

        if (preg_match('/->\s*on\s*\(\s*[\'"]accounts[\'"]/', $code) === 1 || preg_match('/\breferences\s+[`\'"]?accounts\b/i', $code) === 1) {
            $found[] = basename($file).': a key onto accounts';
        }

        foreach (['payable_account_id', 'receivable_account_id', 'sales_discount_account_id'] as $column) {
            if (preg_match('/\b'.$column.'\b/', $code) === 1) {
                $found[] = basename($file).': declares '.$column;
            }
        }
    }

    return $found;
}

it('AC-29.3: no T-029 migration keys onto accounts or declares any of the three deferred account columns (F-17)', function () {
    $directory = (string) File::dirname((string) ptgmSet()->pathFor(ptgmVersion(PTGM_EXPAND)));

    // Over the twelve T-029 files only, copied to a directory of their own.
    $scratch = sys_get_temp_dir().'/ptgm_t029_'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($scratch);

    try {
        foreach (glob($directory.'/2026_11_10_0029*.php') as $file) {
            File::copy($file, $scratch.'/'.basename($file));
        }

        expect(glob($scratch.'/*.php'))->toHaveCount(12)
            ->and(ptgmAccountOffenders($scratch))->toBe([]);
    } finally {
        File::deleteDirectory($scratch);
    }

    // Red-first: a planted migration declaring a deferred column, and one keying onto accounts, are each reported.
    $planted = sys_get_temp_dir().'/ptgm_planted_'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($planted);
    File::put($planted.'/2026_11_10_009999_planted_payable.php', "<?php\nreturn new class { public function up(): void { \$table->ulid('payable_account_id'); } };\n");
    File::put($planted.'/2026_11_10_009998_planted_key.php', "<?php\nreturn new class { public function up(): void { \$table->foreign('x')->references('id')->on('accounts'); } };\n");

    try {
        expect(ptgmAccountOffenders($planted))->toBe([
            '2026_11_10_009998_planted_key.php: a key onto accounts',
            '2026_11_10_009999_planted_payable.php: declares payable_account_id',
        ]);
    } finally {
        File::deleteDirectory($planted);
    }
});

it('AC-29.3 control: a docblock that names the deferred columns in prose is not reported, because comments are stripped', function () {
    $directory = (string) File::dirname((string) ptgmSet()->pathFor(ptgmVersion(PTGM_EXPAND)));

    $authorities = (string) file_get_contents($directory.'/2026_11_10_002901_create_tax_authorities_table.php');

    // The real file's docblock names both columns; the scan must still pass over it.
    expect($authorities)->toContain('payable_account_id')
        ->and(SourceRules::codeOnly($authorities))->not->toContain('payable_account_id');
});
