<?php

use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineDatabase;
use Flexipos\Testing\Engine\EngineOnHold;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| T-027 step 19 - the twelve catalog tables, against a real MySQL/MariaDB
| connection (AC-11's CHECK clause, still NOT VERIFIED while ENGINE_HOLD is
| set; the code to verify it is ready - plan F-6/D-17)
|--------------------------------------------------------------------------
|
| The database here is created and dropped by name, through EngineDatabase,
| in the same run - never a sweep. AC-11's `CHECK (precision BETWEEN 0 AND
| 4)` clause is the one exception AC-42 names and stays NOT VERIFIED with
| the hold. Every other assertion in this file - the FULLTEXT index, the
| JSON validity checks, the generated-column uniqueness, the case-
| insensitive duplicate sku - goes beyond any AC in this spec, and nothing
| is claimed for them while the hold is on either: they are written now, for
| when G-1 clears, the same shape WorkflowSchemaEngineTest/MigrateTenant
| EngineTest already carry for their own engine-only clauses.
*/

function catalogSchemaEngineTenantName(): string
{
    return 'flexipos_t8test_t027_tenant';
}

/**
 * Migrate the fixture tenant and return its connection name.
 */
function catalogSchemaEngineMigrate(): string
{
    $tenantName = catalogSchemaEngineTenantName();
    $tenantConnectionName = EngineConnection::connectionName($tenantName);
    EngineConnection::connection($tenantName);

    expect(Artisan::call('migrate', [
        '--database' => $tenantConnectionName,
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    return $tenantConnectionName;
}

it('refuses to reach the engine at all while the hold is set', function () {
    expect(fn () => EngineConnection::connection(catalogSchemaEngineTenantName()))->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('applies the twelve catalog tables, with the decimal shapes section 4 documents', function () {
    $db = EngineDatabase::engine();
    $tenantName = catalogSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = catalogSchemaEngineMigrate();

        foreach ([
            'units', 'categories', 'brands', 'products', 'unit_conversions',
            'product_barcodes', 'product_locations', 'product_branches',
            'product_components', 'modifier_groups', 'modifiers', 'product_modifier_groups',
        ] as $table) {
            expect(Schema::connection($tenantConnectionName)->hasTable($table))->toBeTrue();
        }

        /** @var array<int, object{table_name: string, column_name: string, column_type: string}> $columns */
        $columns = DB::connection($tenantConnectionName)->select(
            'select table_name, column_name, column_type from information_schema.columns
             where table_schema = ? and table_name in (?, ?, ?, ?, ?)
               and column_name in (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantName,
                'products', 'unit_conversions', 'units', 'product_components', 'modifier_groups',
                'cost_price', 'factor', 'unit_factor', 'base_quantity', 'quantity', 'wastage_percent',
                'weight', 'price_delta', 'linked_quantity',
            ]
        );

        $expected = [
            'products.cost_price' => 'decimal(18,4)',
            'products.weight' => 'decimal(18,4)',
            'unit_conversions.factor' => 'decimal(18,6)',
            'units.factor' => 'decimal(18,6)',
            'product_components.quantity' => 'decimal(18,4)',
            'product_components.unit_factor' => 'decimal(18,6)',
            'product_components.base_quantity' => 'decimal(18,4)',
            'product_components.wastage_percent' => 'decimal(9,4)',
            'modifier_groups' => null,
        ];

        $seen = [];

        foreach ($columns as $column) {
            $seen[$column->table_name.'.'.$column->column_name] = strtolower($column->column_type);
        }

        foreach ($expected as $key => $type) {
            if ($type === null) {
                continue;
            }

            expect($seen[$key] ?? null)->toBe($type, "Expected [{$key}] to be [{$type}], got [".($seen[$key] ?? 'absent').'].');
        }

        // Migrating twice is a no-op: the tree's own idempotence (shared
        // shape with MigrateTenantEngineTest/WorkflowSchemaEngineTest).
        $before = Schema::connection($tenantConnectionName)->getTableListing();

        expect(Artisan::call('migrate', [
            '--database' => $tenantConnectionName,
            '--path' => (string) config('schema.tenant_migration_path'),
            '--force' => true,
        ]))->toBe(0);

        expect(Schema::connection($tenantConnectionName)->getTableListing())->toBe($before);
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it("AC-11's own [ENGINE] clause: precision = 5 is refused by the column's CHECK constraint", function () {
    $db = EngineDatabase::engine();
    $tenantName = catalogSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = catalogSchemaEngineMigrate();

        expect(fn () => DB::connection($tenantConnectionName)->table('units')->insert([
            'id' => Str::upper(Str::ulid()),
            'code' => 'bad-precision',
            'name' => 'Bad precision',
            'dimension' => 'count',
            'precision' => 5,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

        // Control: precision 4, the default, is accepted.
        DB::connection($tenantConnectionName)->table('units')->insert([
            'id' => Str::upper(Str::ulid()),
            'code' => 'good-precision',
            'name' => 'Good precision',
            'dimension' => 'count',
            'precision' => 4,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::connection($tenantConnectionName)->table('units')->where('code', 'good-precision')->exists())->toBeTrue();
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('the FULLTEXT index over products.name/short_name/sku exists', function () {
    $db = EngineDatabase::engine();
    $tenantName = catalogSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = catalogSchemaEngineMigrate();

        /** @var array<int, object{index_type: string}> $rows */
        $rows = DB::connection($tenantConnectionName)->select(
            "select distinct index_type from information_schema.statistics
             where table_schema = ? and table_name = 'products' and index_name = 'products_name_short_name_sku_fulltext'",
            [$tenantName]
        );

        expect($rows)->toHaveCount(1)
            ->and(strtoupper($rows[0]->index_type))->toBe('FULLTEXT');
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('invalid JSON in products.variant_options is refused, and valid JSON is accepted', function () {
    $db = EngineDatabase::engine();
    $tenantName = catalogSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = catalogSchemaEngineMigrate();
        $connection = DB::connection($tenantConnectionName);

        $unitId = Str::upper(Str::ulid());
        $connection->table('units')->insert([
            'id' => $unitId, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count',
            'precision' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $categoryId = Str::upper(Str::ulid());
        $connection->table('categories')->insert([
            'id' => $categoryId, 'code' => 'cat', 'name' => 'Cat', 'path' => $categoryId, 'depth' => 0,
            'sort_order' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $base = [
            'created_at' => now(), 'updated_at' => now(), 'type' => 'simple', 'name' => 'Widget',
            'category_id' => $categoryId, 'stock_unit_id' => $unitId, 'cost_price' => 0, 'selling_price' => 0,
            'track_stock' => 1, 'tracking_mode' => 'quantity', 'is_weighable' => 0, 'price_mode' => 'per_unit',
            'is_sellable' => 1, 'is_purchasable' => 1, 'is_active' => 1, 'allow_price_override' => 0,
            'allow_discount' => 1, 'sort_order' => 0, 'version' => 1,
        ];

        expect(fn () => $connection->table('products')->insert($base + [
            'id' => Str::upper(Str::ulid()), 'sku' => 'bad-json', 'variant_options' => 'not valid json',
        ]))->toThrow(QueryException::class);

        $goodId = Str::upper(Str::ulid());
        $connection->table('products')->insert($base + [
            'id' => $goodId, 'sku' => 'good-json', 'variant_options' => json_encode(['size' => 'L']),
        ]);

        expect($connection->table('products')->where('id', $goodId)->exists())->toBeTrue();
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it("unit_conversions' product_key sentinel and product_barcodes' primary_product_id each refuse their second row", function () {
    $db = EngineDatabase::engine();
    $tenantName = catalogSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = catalogSchemaEngineMigrate();
        $connection = DB::connection($tenantConnectionName);

        $fromUnit = Str::upper(Str::ulid());
        $toUnit = Str::upper(Str::ulid());

        foreach ([$fromUnit, $toUnit] as $index => $unitId) {
            $connection->table('units')->insert([
                'id' => $unitId, 'code' => 'u'.$index, 'name' => 'U'.$index, 'dimension' => 'count',
                'precision' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Two global rows for the same (from, to) pair: product_key folds
        // both NULLs to the same sentinel, so the unique key refuses the
        // second.
        $connection->table('unit_conversions')->insert([
            'id' => Str::upper(Str::ulid()), 'product_id' => null, 'from_unit_id' => $fromUnit,
            'to_unit_id' => $toUnit, 'factor' => 2,
        ]);

        expect(fn () => $connection->table('unit_conversions')->insert([
            'id' => Str::upper(Str::ulid()), 'product_id' => null, 'from_unit_id' => $fromUnit,
            'to_unit_id' => $toUnit, 'factor' => 3,
        ]))->toThrow(UniqueConstraintViolationException::class);

        // primary_product_id: a stored generated column, NULL on every
        // non-primary row (so those are unconstrained) and the product id
        // on the primary one - a second primary for the same product
        // collides on that generated value.
        $categoryId = Str::upper(Str::ulid());
        $connection->table('categories')->insert([
            'id' => $categoryId, 'code' => 'cat2', 'name' => 'Cat2', 'path' => $categoryId, 'depth' => 0,
            'sort_order' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::upper(Str::ulid());
        $connection->table('products')->insert([
            'id' => $productId, 'created_at' => now(), 'updated_at' => now(), 'type' => 'simple',
            'sku' => 'bc-sku', 'name' => 'Barcoded', 'category_id' => $categoryId, 'stock_unit_id' => $fromUnit,
            'cost_price' => 0, 'selling_price' => 0, 'track_stock' => 1, 'tracking_mode' => 'quantity',
            'is_weighable' => 0, 'price_mode' => 'per_unit', 'is_sellable' => 1, 'is_purchasable' => 1,
            'is_active' => 1, 'allow_price_override' => 0, 'allow_discount' => 1, 'sort_order' => 0, 'version' => 1,
        ]);

        $connection->table('product_barcodes')->insert([
            'id' => Str::upper(Str::ulid()), 'product_id' => $productId, 'barcode' => 'bc-1',
            'quantity' => 1, 'is_primary' => 1,
        ]);

        expect(fn () => $connection->table('product_barcodes')->insert([
            'id' => Str::upper(Str::ulid()), 'product_id' => $productId, 'barcode' => 'bc-2',
            'quantity' => 1, 'is_primary' => 1,
        ]))->toThrow(UniqueConstraintViolationException::class);

        // Control: a second non-primary row for the same product is
        // accepted - NULLs are distinct in the generated column's index.
        $connection->table('product_barcodes')->insert([
            'id' => Str::upper(Str::ulid()), 'product_id' => $productId, 'barcode' => 'bc-3',
            'quantity' => 1, 'is_primary' => 0,
        ]);

        expect($connection->table('product_barcodes')->where('barcode', 'bc-3')->exists())->toBeTrue();
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('a case-variant duplicate sku is refused by the unique index, under this connection\'s collation', function () {
    $db = EngineDatabase::engine();
    $tenantName = catalogSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = catalogSchemaEngineMigrate();
        $connection = DB::connection($tenantConnectionName);

        $unitId = Str::upper(Str::ulid());
        $connection->table('units')->insert([
            'id' => $unitId, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count',
            'precision' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $categoryId = Str::upper(Str::ulid());
        $connection->table('categories')->insert([
            'id' => $categoryId, 'code' => 'cat3', 'name' => 'Cat3', 'path' => $categoryId, 'depth' => 0,
            'sort_order' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $base = [
            'created_at' => now(), 'updated_at' => now(), 'type' => 'simple', 'name' => 'Widget',
            'category_id' => $categoryId, 'stock_unit_id' => $unitId, 'cost_price' => 0, 'selling_price' => 0,
            'track_stock' => 1, 'tracking_mode' => 'quantity', 'is_weighable' => 0, 'price_mode' => 'per_unit',
            'is_sellable' => 1, 'is_purchasable' => 1, 'is_active' => 1, 'allow_price_override' => 0,
            'allow_discount' => 1, 'sort_order' => 0, 'version' => 1,
        ];

        $connection->table('products')->insert($base + ['id' => Str::upper(Str::ulid()), 'sku' => 'SKU-001']);

        expect(fn () => $connection->table('products')->insert($base + [
            'id' => Str::upper(Str::ulid()), 'sku' => 'sku-001',
        ]))->toThrow(UniqueConstraintViolationException::class);
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
