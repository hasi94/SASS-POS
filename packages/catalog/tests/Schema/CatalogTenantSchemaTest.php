<?php

namespace Flexipos\Catalog\Tests\Schema;

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
| The twelve catalog tables, applied to a SQLite stand-in (T-027 plan step 3,
| the T-019/T-020 shape)
|--------------------------------------------------------------------------
|
| AC-13 and its backstop, AC-24, AC-28, AC-30, AC-32 to AC-34 schema halves;
| AC-6, AC-27, AC-27a and AC-35 shown by the exact column lists.
|
| No lengths or precisions are asserted here: SQLite reports bare types, and
| AC-44's TenantDecimalPrecisionTest handles those statically.
*/

function catalogSchemaConnection(): string
{
    return 'catalog_schema_stand_in';
}

function catalogApplyTenantTree(): void
{
    config()->set('database.connections.'.catalogSchemaConnection(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge(catalogSchemaConnection());

    expect(Artisan::call('migrate', [
        '--database' => catalogSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * @return list<string>
 */
function catalogColumns(string $table): array
{
    $names = array_column(Schema::connection(catalogSchemaConnection())->getColumns($table), 'name');
    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function catalogNullableColumns(string $table): array
{
    $names = array_column(
        array_filter(Schema::connection(catalogSchemaConnection())->getColumns($table), static fn (array $column): bool => $column['nullable']),
        'name'
    );
    sort($names);

    return $names;
}

function catalogNow(): string
{
    return '2026-11-08 00:00:00.000';
}

/**
 * Minimal seed rows this file's inserts build on: one of every unit
 * dimension, a category, a brand and a stock-unit product.
 *
 * @return array{each: string, kg: string, g: string, l: string, category: string, brand: string, product: string}
 */
function catalogSeedMinimum(): array
{
    $connection = DB::connection(catalogSchemaConnection());
    $now = catalogNow();

    $each = str_pad('each', 26, 'x');
    $kg = str_pad('kg', 26, 'x');
    $g = str_pad('g', 26, 'x');
    $l = str_pad('l', 26, 'x');

    $connection->table('units')->insert([
        ['id' => $each, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count', 'precision' => 0, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $kg, 'code' => 'kg', 'name' => 'Kilogram', 'dimension' => 'mass', 'precision' => 3, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $g, 'code' => 'g', 'name' => 'Gram', 'dimension' => 'mass', 'precision' => 0, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $l, 'code' => 'l', 'name' => 'Litre', 'dimension' => 'volume', 'precision' => 3, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $category = str_pad('cat1', 26, 'x');
    $connection->table('categories')->insert([
        'id' => $category, 'created_at' => $now, 'updated_at' => $now,
        'code' => 'c1', 'name' => 'Cat 1', 'path' => $category, 'depth' => 0,
    ]);

    $brand = str_pad('brand1', 26, 'x');
    $connection->table('brands')->insert([
        'id' => $brand, 'created_at' => $now, 'updated_at' => $now, 'code' => 'b1', 'name' => 'Brand 1',
    ]);

    $product = str_pad('prod1', 26, 'x');
    $connection->table('products')->insert([
        'id' => $product, 'created_at' => $now, 'updated_at' => $now,
        'type' => 'simple', 'sku' => 'sku1', 'name' => 'Product 1',
        'category_id' => $category, 'stock_unit_id' => $each,
    ]);

    return ['each' => $each, 'kg' => $kg, 'g' => $g, 'l' => $l, 'category' => $category, 'brand' => $brand, 'product' => $product];
}

it('creates units with exactly the documented columns', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('units'))->toBe([
        'base_unit_id', 'code', 'created_at', 'dimension', 'factor', 'id', 'is_active', 'name', 'precision', 'updated_at',
    ])->and(catalogNullableColumns('units'))->toBe(['base_unit_id', 'factor']);
});

it('creates categories with exactly the documented columns', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('categories'))->toBe([
        'code', 'colour', 'created_at', 'deleted_at', 'depth', 'id', 'image_path', 'income_account_id', 'is_active',
        'name', 'parent_id', 'path', 'sort_order', 'updated_at',
    ])->and(catalogNullableColumns('categories'))->toBe(['colour', 'deleted_at', 'image_path', 'income_account_id', 'parent_id'])
        // T-032 AC-52: income only; a categories cogs column is not added.
        ->and(catalogColumns('categories'))->not->toContain('cogs_account_id');
});

it('creates brands with exactly the documented columns', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('brands'))->toBe([
        'code', 'created_at', 'deleted_at', 'id', 'is_active', 'logo_path', 'name', 'updated_at',
    ])->and(catalogNullableColumns('brands'))->toBe(['deleted_at', 'logo_path']);
});

it('creates products with exactly the documented columns, no custom_fields and no still-deferred FK columns (AC-27, AC-27a, T-029 AC-1, AC-2)', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('products'))->toBe([
        'allow_discount', 'allow_price_override', 'brand_id', 'category_id', 'cogs_account_id', 'cost_price', 'created_at',
        'created_by', 'deleted_at', 'description', 'dimensions', 'id', 'image_path', 'income_account_id',
        'inventory_account_id', 'is_active',
        'is_purchasable', 'is_sellable', 'is_weighable', 'min_selling_price', 'msrp', 'name', 'parent_id',
        'plu_code', 'price_mode', 'purchase_tax_group_id', 'purchase_unit_id', 'sales_unit_id', 'selling_price', 'shelf_life_days',
        'short_name', 'sku', 'sort_order', 'stock_unit_id', 'tax_group_id', 'track_stock', 'tracking_mode', 'type',
        'updated_at', 'updated_by', 'variant_options', 'variant_values', 'version', 'weight',
    ])->and(catalogColumns('products'))->not->toContain('custom_fields')
        // T-029 step 3 built the two tax group columns T-027 deferred, and
        // T-032 step 17 the three account columns (present now, AC-52).
        ->and(catalogColumns('products'))->toContain('income_account_id', 'cogs_account_id', 'inventory_account_id')
        ->and(catalogColumns('products'))->not->toContain('kitchen_station_id');
});

it('creates unit_conversions with exactly the documented columns, no timestamps', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('unit_conversions'))->toBe([
        'factor', 'from_unit_id', 'id', 'product_id', 'product_key', 'to_unit_id',
    ])
        // `product_key` is `IdentifierColumn::sentinel()`'s stored generated
        // column: `COALESCE(product_id, '-')` can never itself be null, but
        // SQLite's own `getColumns()` reports a generated column as
        // nullable regardless of the expression behind it (the stand-in's
        // own limit, not a declared nullability - see `IdentifierColumn::
        // sentinel()`, which issues no `->nullable()` call).
        ->and(catalogNullableColumns('unit_conversions'))->toBe(['product_id', 'product_key']);
});

it('creates product_barcodes with exactly the documented columns, no timestamps (AC-43 degenerate pair)', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('product_barcodes'))->toBe([
        'barcode', 'id', 'is_primary', 'primary_product_id', 'product_id', 'quantity', 'unit_id',
    ])->and(catalogNullableColumns('product_barcodes'))->toBe(['primary_product_id', 'unit_id']);
});

it('creates product_locations with exactly the documented columns', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('product_locations'))->toBe([
        'bin_location', 'created_at', 'is_available', 'location_id', 'max_level', 'product_id',
        'reorder_level', 'reorder_qty', 'updated_at',
    ]);
});

it('creates product_branches with exactly the documented columns', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('product_branches'))->toBe([
        'branch_id', 'created_at', 'is_available', 'product_id', 'selling_price', 'updated_at',
    ])->and(catalogNullableColumns('product_branches'))->toBe(['selling_price']);
});

it('creates product_components with exactly the documented [UOM] quad, no timestamps (AC-43)', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('product_components'))->toBe([
        'base_quantity', 'component_product_id', 'id', 'is_optional', 'parent_product_id', 'quantity',
        'sort_order', 'unit_factor', 'unit_id', 'wastage_percent',
    ]);
});

it('creates modifier_groups with exactly the documented columns', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('modifier_groups'))->toBe([
        'created_at', 'deleted_at', 'id', 'is_required', 'max_select', 'min_select', 'name', 'sort_order', 'updated_at',
    ])->and(catalogNullableColumns('modifier_groups'))->toBe(['deleted_at', 'max_select']);
});

it('creates modifiers with exactly the documented degenerate pair, no unit_factor (AC-40a, AC-43)', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('modifiers'))->toBe([
        'cost_delta', 'group_id', 'id', 'is_active', 'kitchen_note', 'linked_product_id', 'linked_quantity',
        'linked_unit_id', 'name', 'price_delta', 'sort_order',
    ])->and(catalogColumns('modifiers'))->not->toContain('unit_factor')
        ->and(catalogColumns('modifiers'))->not->toContain('base_quantity');
});

it('creates product_modifier_groups with exactly the documented columns', function () {
    catalogApplyTenantTree();

    expect(catalogColumns('product_modifier_groups'))->toBe(['group_id', 'product_id', 'sort_order']);
});

it('holds the units.code unique key', function () {
    catalogApplyTenantTree();
    $connection = DB::connection(catalogSchemaConnection());
    $now = catalogNow();

    $connection->table('units')->insert([
        'id' => str_pad('u1', 26, 'x'), 'code' => 'kg', 'name' => 'Kilogram', 'dimension' => 'mass', 'created_at' => $now, 'updated_at' => $now,
    ]);

    expect(fn () => $connection->table('units')->insert([
        'id' => str_pad('u2', 26, 'x'), 'code' => 'kg', 'name' => 'Kilogram again', 'dimension' => 'mass', 'created_at' => $now, 'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});

it('holds the categories.code and brands.code/name unique keys (AC-1, AC-7)', function () {
    catalogApplyTenantTree();
    $seed = catalogSeedMinimum();
    $connection = DB::connection(catalogSchemaConnection());
    $now = catalogNow();

    expect(fn () => $connection->table('categories')->insert([
        'id' => str_pad('cat2', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'code' => 'c1', 'name' => 'Cat 2', 'path' => str_pad('cat2', 26, 'x'), 'depth' => 0,
    ]))->toThrow(QueryException::class);

    expect(fn () => $connection->table('brands')->insert([
        'id' => str_pad('brand2', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'b1', 'name' => 'Different name',
    ]))->toThrow(QueryException::class);

    expect(fn () => $connection->table('brands')->insert([
        'id' => str_pad('brand3', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'different-code', 'name' => 'Brand 1',
    ]))->toThrow(QueryException::class);
});

it('holds products.sku and plu_code unique even against a soft-deleted row (AC-24)', function () {
    catalogApplyTenantTree();
    $seed = catalogSeedMinimum();
    $connection = DB::connection(catalogSchemaConnection());
    $now = catalogNow();

    $connection->table('products')->where('id', $seed['product'])->update([
        'plu_code' => 'PLU1', 'deleted_at' => $now,
    ]);

    expect(fn () => $connection->table('products')->insert([
        'id' => str_pad('prod2', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'type' => 'simple', 'sku' => 'sku1', 'name' => 'Product 2',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'],
    ]))->toThrow(QueryException::class);

    expect(fn () => $connection->table('products')->insert([
        'id' => str_pad('prod3', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'type' => 'simple', 'sku' => 'sku3', 'name' => 'Product 3', 'plu_code' => 'PLU1',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'],
    ]))->toThrow(QueryException::class);
});

it('holds the unit_conversions unique key: two global rows for the same pair refuse, a global and a product row coexist, two product rows for one product refuse (AC-13 backstop)', function () {
    catalogApplyTenantTree();
    $seed = catalogSeedMinimum();
    $connection = DB::connection(catalogSchemaConnection());

    $connection->table('unit_conversions')->insert([
        'id' => str_pad('uc1', 26, 'x'), 'product_id' => null, 'from_unit_id' => $seed['kg'], 'to_unit_id' => $seed['g'], 'factor' => 1000,
    ]);

    // Two global rows for the same pair.
    expect(fn () => $connection->table('unit_conversions')->insert([
        'id' => str_pad('uc2', 26, 'x'), 'product_id' => null, 'from_unit_id' => $seed['kg'], 'to_unit_id' => $seed['g'], 'factor' => 999,
    ]))->toThrow(QueryException::class);

    // A product-scoped row for the same pair coexists with the global one.
    $connection->table('unit_conversions')->insert([
        'id' => str_pad('uc3', 26, 'x'), 'product_id' => $seed['product'], 'from_unit_id' => $seed['kg'], 'to_unit_id' => $seed['g'], 'factor' => 1001,
    ]);

    // A second product-scoped row for the same product and pair refuses.
    expect(fn () => $connection->table('unit_conversions')->insert([
        'id' => str_pad('uc4', 26, 'x'), 'product_id' => $seed['product'], 'from_unit_id' => $seed['kg'], 'to_unit_id' => $seed['g'], 'factor' => 1002,
    ]))->toThrow(QueryException::class);
});

it('holds exactly one primary barcode per product (AC-30 backstop)', function () {
    catalogApplyTenantTree();
    $seed = catalogSeedMinimum();
    $connection = DB::connection(catalogSchemaConnection());

    $connection->table('product_barcodes')->insert([
        'id' => str_pad('bc1', 26, 'x'), 'product_id' => $seed['product'], 'barcode' => '1111', 'is_primary' => true,
    ]);

    // A second primary row for the SAME product refuses.
    expect(fn () => $connection->table('product_barcodes')->insert([
        'id' => str_pad('bc2', 26, 'x'), 'product_id' => $seed['product'], 'barcode' => '2222', 'is_primary' => true,
    ]))->toThrow(QueryException::class);

    // Two non-primary rows are accepted.
    $connection->table('product_barcodes')->insert([
        'id' => str_pad('bc3', 26, 'x'), 'product_id' => $seed['product'], 'barcode' => '3333', 'is_primary' => false,
    ]);
    $connection->table('product_barcodes')->insert([
        'id' => str_pad('bc4', 26, 'x'), 'product_id' => $seed['product'], 'barcode' => '4444', 'is_primary' => false,
    ]);

    // A different product's primary is accepted.
    $connection->table('products')->insert([
        'id' => str_pad('prod9', 26, 'x'), 'created_at' => catalogNow(), 'updated_at' => catalogNow(),
        'type' => 'simple', 'sku' => 'sku9', 'name' => 'Product 9',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'],
    ]);
    $connection->table('product_barcodes')->insert([
        'id' => str_pad('bc5', 26, 'x'), 'product_id' => str_pad('prod9', 26, 'x'), 'barcode' => '5555', 'is_primary' => true,
    ]);

    expect($connection->table('product_barcodes')->where('is_primary', true)->count())->toBe(2);
});

it('holds the product_locations, product_branches, product_components and product_modifier_groups composite keys', function () {
    catalogApplyTenantTree();
    $seed = catalogSeedMinimum();
    $connection = DB::connection(catalogSchemaConnection());
    $now = catalogNow();

    $connection->table('locations')->insert([
        'id' => str_pad('loc1', 26, 'x'), 'code' => 'loc1', 'name' => 'Loc 1', 'type' => 'warehouse',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $connection->table('product_locations')->insert([
        'product_id' => $seed['product'], 'location_id' => str_pad('loc1', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
    ]);

    expect(fn () => $connection->table('product_locations')->insert([
        'product_id' => $seed['product'], 'location_id' => str_pad('loc1', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
    ]))->toThrow(QueryException::class);

    $connection->table('branches')->insert([
        'id' => str_pad('br1', 26, 'x'), 'code' => 'br1', 'name' => 'Branch 1', 'timezone' => 'UTC',
        'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $connection->table('product_branches')->insert([
        'product_id' => $seed['product'], 'branch_id' => str_pad('br1', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
    ]);

    expect(fn () => $connection->table('product_branches')->insert([
        'product_id' => $seed['product'], 'branch_id' => str_pad('br1', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
    ]))->toThrow(QueryException::class);

    $connection->table('products')->insert([
        'id' => str_pad('prod8', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'type' => 'simple', 'sku' => 'sku8', 'name' => 'Product 8',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'],
    ]);

    $connection->table('product_components')->insert([
        'id' => str_pad('pc1', 26, 'x'), 'parent_product_id' => $seed['product'], 'component_product_id' => str_pad('prod8', 26, 'x'),
        'unit_id' => $seed['each'], 'quantity' => 1, 'unit_factor' => 1, 'base_quantity' => 1,
    ]);

    expect(fn () => $connection->table('product_components')->insert([
        'id' => str_pad('pc2', 26, 'x'), 'parent_product_id' => $seed['product'], 'component_product_id' => str_pad('prod8', 26, 'x'),
        'unit_id' => $seed['each'], 'quantity' => 2, 'unit_factor' => 1, 'base_quantity' => 2,
    ]))->toThrow(QueryException::class);

    $connection->table('modifier_groups')->insert([
        'id' => str_pad('mg1', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'name' => 'Size',
    ]);

    $connection->table('product_modifier_groups')->insert([
        'product_id' => $seed['product'], 'group_id' => str_pad('mg1', 26, 'x'),
    ]);

    expect(fn () => $connection->table('product_modifier_groups')->insert([
        'product_id' => $seed['product'], 'group_id' => str_pad('mg1', 26, 'x'),
    ]))->toThrow(QueryException::class);
});

it('holds exactly the 29 documented foreign keys (27 from T-027, 2 from T-029)', function () {
    catalogApplyTenantTree();

    $tables = [
        'categories', 'modifiers', 'product_barcodes', 'product_branches', 'product_components',
        'product_locations', 'product_modifier_groups', 'products', 'unit_conversions', 'units',
    ];

    $keys = [];

    foreach ($tables as $table) {
        foreach (DB::connection(catalogSchemaConnection())->select('pragma foreign_key_list("'.$table.'")') as $key) {
            $key = (array) $key;
            $keys[] = $table.'.'.$key['from'].' -> '.$key['table'].'.'.$key['to'];
        }
    }

    sort($keys);

    expect($keys)->toBe([
        // T-032 step 17: the five owed account keys (three here, sorted in).
        'categories.income_account_id -> accounts.id',
        'categories.parent_id -> categories.id',
        'modifiers.group_id -> modifier_groups.id',
        'modifiers.linked_product_id -> products.id',
        'modifiers.linked_unit_id -> units.id',
        'product_barcodes.product_id -> products.id',
        'product_barcodes.unit_id -> units.id',
        'product_branches.branch_id -> branches.id',
        'product_branches.product_id -> products.id',
        'product_components.component_product_id -> products.id',
        'product_components.parent_product_id -> products.id',
        'product_components.unit_id -> units.id',
        'product_locations.location_id -> locations.id',
        'product_locations.product_id -> products.id',
        'product_modifier_groups.group_id -> modifier_groups.id',
        'product_modifier_groups.product_id -> products.id',
        'products.brand_id -> brands.id',
        'products.category_id -> categories.id',
        'products.cogs_account_id -> accounts.id',
        'products.created_by -> employees.id',
        'products.income_account_id -> accounts.id',
        'products.inventory_account_id -> accounts.id',
        'products.parent_id -> products.id',
        'products.purchase_tax_group_id -> tax_groups.id',
        'products.purchase_unit_id -> units.id',
        'products.sales_unit_id -> units.id',
        'products.stock_unit_id -> units.id',
        'products.tax_group_id -> tax_groups.id',
        'products.updated_by -> employees.id',
        'unit_conversions.from_unit_id -> units.id',
        'unit_conversions.product_id -> products.id',
        'unit_conversions.to_unit_id -> units.id',
        'units.base_unit_id -> units.id',
    ]);
});

it('cascades both documented deletes: product_barcodes on its product, modifiers on their group', function () {
    catalogApplyTenantTree();
    $seed = catalogSeedMinimum();
    $connection = DB::connection(catalogSchemaConnection());
    $now = catalogNow();

    $connection->table('product_barcodes')->insert([
        'id' => str_pad('bcc1', 26, 'x'), 'product_id' => $seed['product'], 'barcode' => '9999', 'is_primary' => false,
    ]);

    $connection->table('products')->where('id', $seed['product'])->delete();

    expect($connection->table('product_barcodes')->where('id', str_pad('bcc1', 26, 'x'))->exists())->toBeFalse();

    $groupId = str_pad('mgc1', 26, 'x');
    $connection->table('modifier_groups')->insert([
        'id' => $groupId, 'created_at' => $now, 'updated_at' => $now, 'name' => 'Extras',
    ]);
    $connection->table('modifiers')->insert([
        'id' => str_pad('modc1', 26, 'x'), 'group_id' => $groupId, 'name' => 'Extra cheese',
    ]);

    $connection->table('modifier_groups')->where('id', $groupId)->delete();

    expect($connection->table('modifiers')->where('id', str_pad('modc1', 26, 'x'))->exists())->toBeFalse();
});

it('runs cleanly twice as a no-op, and no catalog file declares a down()', function () {
    catalogApplyTenantTree();

    $before = Schema::connection(catalogSchemaConnection())->getTableListing();

    expect(Artisan::call('migrate', [
        '--database' => catalogSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    expect(Schema::connection(catalogSchemaConnection())->getTableListing())->toBe($before);

    foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_08_0027*.php') as $file) {
        expect(file_get_contents($file))->not->toContain('function down(');
    }
});

it('reports no SourceRules violation across the twelve catalog migrations', function () {
    $violations = [];

    foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_08_0027*.php') as $file) {
        $violations = array_merge($violations, SourceRules::inspect((string) file_get_contents($file), MigrationTree::Tenant));
    }

    expect($violations)->toBe([]);
});
