<?php

namespace Flexipos\Purchasing\Tests\Schema;

use Flexipos\Kernel\Schema\Support\MigrationPhase;
use Flexipos\Kernel\Schema\Support\MigrationSet;
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
| The six Purchasing tables, applied to a SQLite stand-in (T-034 plan step 4,
| the T-030 SalesTenantSchemaTest shape)
|--------------------------------------------------------------------------
|
| The schema halves of AC-1, AC-6, AC-19, AC-40, AC-68, AC-88 and AC-89. Each
| table's sorted column list is asserted EXACTLY, with its nullable subset, so
| the absence of every deferred column (D-3: custom_fields, opening_balance,
| payable_account_id, default_expense_account_id, unit_code, tax_breakdown) is
| proved by equality and not by a list of things that are not there.
|
| No lengths or precisions are asserted here: SQLite reports bare types, and
| TenantDecimalPrecisionTest handles those statically.
|
| No catalogue version is written as a literal (plan section 0.4): the six
| catalogue rows are looked up by migration name and asserted consecutive,
| expand, not destructive and not offline. Rows are inserted with foreign keys
| OFF where a case is about a unique key and not a parent; the cascade and
| restrict cases turn them on for the delete, which is when SQLite enforces.
*/

const PU_SCHEMA_SUPPLIERS = '2026_11_16_003401_create_suppliers_table';

const PU_SCHEMA_REQUESTS = '2026_11_16_003402_create_purchase_requests_table';

const PU_SCHEMA_ORDERS = '2026_11_16_003403_create_purchase_orders_table';

const PU_SCHEMA_ORDER_ITEMS = '2026_11_16_003404_create_purchase_order_items_table';

const PU_SCHEMA_REQUEST_ITEMS = '2026_11_16_003405_create_purchase_request_items_table';

const PU_SCHEMA_APPLICATIONS = '2026_11_16_003406_create_purchase_order_receipt_applications_table';

const PU_SCHEMA_NOW = '2026-11-16 00:00:00.000';

/**
 * @return list<string>
 */
function puSchemaMigrations(): array
{
    return [PU_SCHEMA_SUPPLIERS, PU_SCHEMA_REQUESTS, PU_SCHEMA_ORDERS, PU_SCHEMA_ORDER_ITEMS, PU_SCHEMA_REQUEST_ITEMS, PU_SCHEMA_APPLICATIONS];
}

/**
 * @return list<string>
 */
function puSchemaFiles(): array
{
    return array_map(
        static fn (string $name): string => base_path((string) config('schema.tenant_migration_path')).'/'.$name.'.php',
        puSchemaMigrations(),
    );
}

function puSchemaConnection(): string
{
    return 'purchasing_schema_stand_in';
}

function puSchemaApply(): void
{
    config()->set('database.connections.'.puSchemaConnection(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge(puSchemaConnection());

    expect(Artisan::call('migrate', [
        '--database' => puSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * @return list<string>
 */
function puSchemaColumns(string $table): array
{
    $names = array_column(Schema::connection(puSchemaConnection())->getColumns($table), 'name');
    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function puSchemaNullable(string $table): array
{
    $names = array_column(
        array_filter(Schema::connection(puSchemaConnection())->getColumns($table), static fn (array $column): bool => $column['nullable']),
        'name'
    );
    sort($names);

    return $names;
}

/**
 * Which of the named columns a table has: the absence assertions' collector. A
 * negated multi-needle toContain() passes when EITHER needle is missing, so
 * absence is asserted as "this list is empty" instead.
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function puSchemaPresent(string $table, array $names): array
{
    return array_values(array_filter($names, static fn (string $name): bool => in_array($name, puSchemaColumns($table), true)));
}

function puSchemaId(string $label): string
{
    return str_pad($label, 26, 'x');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puSchemaSupplier(string $label, array $overrides = []): array
{
    return array_merge([
        'id' => puSchemaId($label), 'created_at' => PU_SCHEMA_NOW, 'updated_at' => PU_SCHEMA_NOW,
        'code' => 'SUP-'.$label, 'name' => 'Supplier '.$label,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puSchemaRequest(string $label, array $overrides = []): array
{
    return array_merge([
        'id' => puSchemaId($label), 'created_at' => PU_SCHEMA_NOW, 'updated_at' => PU_SCHEMA_NOW,
        'number' => 'PR-'.$label, 'branch_id' => puSchemaId('br1'), 'location_id' => puSchemaId('loc1'),
        'requested_by' => puSchemaId('emp1'), 'status' => 'draft',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puSchemaOrder(string $label, array $overrides = []): array
{
    return array_merge([
        'id' => puSchemaId($label), 'created_at' => PU_SCHEMA_NOW, 'updated_at' => PU_SCHEMA_NOW,
        'number' => 'PO-'.$label, 'supplier_id' => puSchemaId('sup1'), 'supplier_name' => 'Supplier', 'payment_terms_days' => 30,
        'branch_id' => puSchemaId('br1'), 'location_id' => puSchemaId('loc1'), 'order_date' => '2026-11-16', 'status' => 'draft',
        'currency' => 'USD', 'subtotal' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'shipping' => '0.0000',
        'total' => '0.0000',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puSchemaOrderItem(string $label, string $orderLabel, int $lineNo, array $overrides = []): array
{
    return array_merge([
        'id' => puSchemaId($label), 'order_id' => puSchemaId($orderLabel), 'line_no' => $lineNo,
        'product_id' => puSchemaId('prod1'), 'description' => 'Cola', 'unit_id' => puSchemaId('unit1'),
        'quantity' => '1.0000', 'unit_factor' => '1.000000', 'base_quantity' => '1.0000', 'unit_cost' => '5.0000',
        'subtotal' => '5.0000', 'discount_amount' => '0.0000', 'tax_group_id' => puSchemaId('tg1'), 'tax_amount' => '0.0000',
        'line_total' => '5.0000',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puSchemaRequestItem(string $label, string $requestLabel, int $lineNo, array $overrides = []): array
{
    return array_merge([
        'id' => puSchemaId($label), 'request_id' => puSchemaId($requestLabel), 'line_no' => $lineNo,
        'product_id' => puSchemaId('prod1'), 'unit_id' => puSchemaId('unit1'),
        'quantity' => '1.0000', 'unit_factor' => '1.000000', 'base_quantity' => '1.0000',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puSchemaApplication(string $label, string $orderLabel, string $itemLabel, array $overrides = []): array
{
    return array_merge([
        'id' => puSchemaId($label), 'order_id' => puSchemaId($orderLabel), 'order_item_id' => puSchemaId($itemLabel),
        'received_base_delta' => '1.0000', 'received_quantity_delta' => '1.0000', 'created_at' => PU_SCHEMA_NOW,
    ], $overrides);
}

it('creates suppliers with exactly the documented columns, none of the four deferred ones (AC-1, AC-6, D-3)', function () {
    puSchemaApply();

    expect(puSchemaColumns('suppliers'))->toBe([
        'address', 'bank_details', 'code', 'contact_name', 'created_at', 'created_by', 'credit_limit', 'currency', 'deleted_at',
        'email', 'id', 'is_active', 'legal_name', 'name', 'notes', 'payment_terms_days', 'phone', 'tax_no', 'updated_at',
        'updated_by',
    ])->and(puSchemaNullable('suppliers'))->toBe([
        'address', 'bank_details', 'contact_name', 'created_by', 'currency', 'deleted_at', 'email', 'legal_name', 'notes',
        'phone', 'tax_no', 'updated_by',
    ])->and(puSchemaPresent('suppliers', ['custom_fields', 'opening_balance', 'payable_account_id', 'default_expense_account_id']))->toBe([]);
});

it('creates purchase_requests with exactly the documented columns (AC-19)', function () {
    puSchemaApply();

    expect(puSchemaColumns('purchase_requests'))->toBe([
        'branch_id', 'created_at', 'created_by', 'id', 'location_id', 'notes', 'number', 'requested_by', 'required_date',
        'status', 'updated_at', 'updated_by', 'workflow_instance_id',
    ])->and(puSchemaNullable('purchase_requests'))->toBe([
        'created_by', 'notes', 'required_date', 'updated_by', 'workflow_instance_id',
    ]);
});

it('creates purchase_orders with exactly the documented columns, no custom_fields (AC-40, D-3)', function () {
    puSchemaApply();

    expect(puSchemaColumns('purchase_orders'))->toBe([
        'branch_id', 'created_at', 'created_by', 'currency', 'discount_total', 'exchange_rate', 'expected_date', 'id',
        'location_id', 'notes', 'number', 'order_date', 'payment_terms_days', 'received_value', 'request_id', 'shipping',
        'status', 'subtotal', 'supplier_id', 'supplier_name', 'supplier_tax_no', 'tax_total', 'terms', 'total', 'updated_at',
        'updated_by', 'workflow_instance_id',
    ])->and(puSchemaNullable('purchase_orders'))->toBe([
        'created_by', 'expected_date', 'notes', 'request_id', 'supplier_tax_no', 'terms', 'updated_by', 'workflow_instance_id',
    ])->and(puSchemaPresent('purchase_orders', ['custom_fields']))->toBe([]);
});

it('creates purchase_order_items with exactly the documented columns, no unit_code or tax_breakdown (AC-42, D-3, D-27)', function () {
    puSchemaApply();

    expect(puSchemaColumns('purchase_order_items'))->toBe([
        'base_quantity', 'description', 'discount_amount', 'discount_percent', 'expected_date', 'id', 'line_no', 'line_total',
        'order_id', 'product_id', 'quantity', 'received_base_quantity', 'received_quantity', 'subtotal', 'tax_amount',
        'tax_group_id', 'unit_cost', 'unit_factor', 'unit_id',
    ])->and(puSchemaNullable('purchase_order_items'))->toBe(['expected_date'])
        ->and(puSchemaPresent('purchase_order_items', ['unit_code', 'tax_breakdown']))->toBe([]);
});

it('creates purchase_request_items with exactly the documented columns (AC-19)', function () {
    puSchemaApply();

    expect(puSchemaColumns('purchase_request_items'))->toBe([
        'base_quantity', 'estimated_cost', 'id', 'line_no', 'product_id', 'purchase_order_item_id', 'quantity', 'request_id',
        'supplier_id', 'unit_factor', 'unit_id',
    ])->and(puSchemaNullable('purchase_request_items'))->toBe(['estimated_cost', 'purchase_order_item_id', 'supplier_id']);
});

it('creates purchase_order_receipt_applications with exactly the documented columns (AC-68)', function () {
    puSchemaApply();

    expect(puSchemaColumns('purchase_order_receipt_applications'))->toBe([
        'created_at', 'created_by', 'id', 'order_id', 'order_item_id', 'received_base_delta', 'received_quantity_delta',
    ])->and(puSchemaNullable('purchase_order_receipt_applications'))->toBe(['created_by']);
});

it('builds no table with a tenant_id column (the database is the boundary)', function () {
    puSchemaApply();

    $present = [];

    foreach (['suppliers', 'purchase_requests', 'purchase_orders', 'purchase_order_items', 'purchase_request_items', 'purchase_order_receipt_applications'] as $table) {
        $present = array_merge($present, puSchemaPresent($table, ['tenant_id']));
    }

    expect($present)->toBe([]);
});

it('makes suppliers.credit_limit NOT NULL with a default of 0, and payment_terms_days default 0 (AC-6, D-32)', function () {
    puSchemaApply();
    $connection = DB::connection(puSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $columns = [];

    foreach (Schema::connection(puSchemaConnection())->getColumns('suppliers') as $column) {
        $columns[$column['name']] = $column;
    }

    expect($columns['credit_limit']['nullable'])->toBeFalse()
        ->and($columns['payment_terms_days']['nullable'])->toBeFalse()
        ->and($columns['is_active']['nullable'])->toBeFalse();

    $connection->table('suppliers')->insert(puSchemaSupplier('sup1'));

    $row = (array) $connection->table('suppliers')->where('id', puSchemaId('sup1'))->first();

    expect((float) $row['credit_limit'])->toBe(0.0)
        ->and((int) $row['payment_terms_days'])->toBe(0)
        ->and((int) $row['is_active'])->toBe(1);

    expect(fn () => $connection->table('suppliers')->insert(puSchemaSupplier('sup2', ['credit_limit' => null])))
        ->toThrow(QueryException::class);
});

it('holds suppliers.code unique across every row, a deleted holder included', function () {
    puSchemaApply();
    $connection = DB::connection(puSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $connection->table('suppliers')->insert(puSchemaSupplier('sup1', ['code' => 'ACME', 'deleted_at' => PU_SCHEMA_NOW]));

    expect(fn () => $connection->table('suppliers')->insert(puSchemaSupplier('sup2', ['code' => 'ACME'])))
        ->toThrow(QueryException::class);

    $connection->table('suppliers')->insert(puSchemaSupplier('sup3', ['code' => 'OTHER']));

    expect($connection->table('suppliers')->count())->toBe(2);
});

it('holds both document numbers unique, and each (header, line_no) unique', function () {
    puSchemaApply();
    $connection = DB::connection(puSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $connection->table('purchase_requests')->insert(puSchemaRequest('req1', ['number' => 'PR000001']));

    expect(fn () => $connection->table('purchase_requests')->insert(puSchemaRequest('req2', ['number' => 'PR000001'])))
        ->toThrow(QueryException::class);

    $connection->table('purchase_orders')->insert(puSchemaOrder('ord1', ['number' => 'PO000001']));

    expect(fn () => $connection->table('purchase_orders')->insert(puSchemaOrder('ord2', ['number' => 'PO000001'])))
        ->toThrow(QueryException::class);

    $connection->table('purchase_order_items')->insert(puSchemaOrderItem('oi1', 'ord1', 1));
    $connection->table('purchase_order_items')->insert(puSchemaOrderItem('oi2', 'ord1', 2));

    expect(fn () => $connection->table('purchase_order_items')->insert(puSchemaOrderItem('oi3', 'ord1', 1)))
        ->toThrow(QueryException::class);

    $connection->table('purchase_request_items')->insert(puSchemaRequestItem('ri1', 'req1', 1));

    expect(fn () => $connection->table('purchase_request_items')->insert(puSchemaRequestItem('ri2', 'req1', 1)))
        ->toThrow(QueryException::class);

    // The same line number under another header is a different key.
    $connection->table('purchase_orders')->insert(puSchemaOrder('ord3', ['number' => 'PO000003']));
    $connection->table('purchase_order_items')->insert(puSchemaOrderItem('oi4', 'ord3', 1));
    $connection->table('purchase_requests')->insert(puSchemaRequest('req3', ['number' => 'PR000003']));
    $connection->table('purchase_request_items')->insert(puSchemaRequestItem('ri3', 'req3', 1));

    expect($connection->table('purchase_order_items')->count())->toBe(3)
        ->and($connection->table('purchase_request_items')->count())->toBe(2);
});

it('defaults the order money that has a default: exchange_rate 1, received_value 0, and the line columns (AC-40, AC-68)', function () {
    puSchemaApply();
    $connection = DB::connection(puSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $connection->table('purchase_orders')->insert(puSchemaOrder('ord1'));
    $connection->table('purchase_order_items')->insert(puSchemaOrderItem('oi1', 'ord1', 1));

    $order = (array) $connection->table('purchase_orders')->where('id', puSchemaId('ord1'))->first();
    $item = (array) $connection->table('purchase_order_items')->where('id', puSchemaId('oi1'))->first();

    expect((float) $order['exchange_rate'])->toBe(1.0)
        ->and((float) $order['received_value'])->toBe(0.0)
        ->and((float) $item['discount_percent'])->toBe(0.0)
        ->and((float) $item['received_quantity'])->toBe(0.0)
        ->and((float) $item['received_base_quantity'])->toBe(0.0);
});

it('holds exactly the 27 documented foreign keys on the six new tables', function () {
    puSchemaApply();

    $tables = ['purchase_order_items', 'purchase_order_receipt_applications', 'purchase_orders', 'purchase_request_items', 'purchase_requests', 'suppliers'];
    $keys = [];

    foreach ($tables as $table) {
        foreach (DB::connection(puSchemaConnection())->select('pragma foreign_key_list("'.$table.'")') as $key) {
            $key = (array) $key;
            $keys[] = $table.'.'.$key['from'].' -> '.$key['table'].'.'.$key['to'].($key['on_delete'] === 'CASCADE' ? ' (cascade)' : '');
        }
    }

    sort($keys);

    expect($keys)->toBe([
        'purchase_order_items.order_id -> purchase_orders.id (cascade)',
        'purchase_order_items.product_id -> products.id',
        'purchase_order_items.tax_group_id -> tax_groups.id',
        'purchase_order_items.unit_id -> units.id',
        'purchase_order_receipt_applications.created_by -> employees.id',
        'purchase_order_receipt_applications.order_id -> purchase_orders.id',
        'purchase_order_receipt_applications.order_item_id -> purchase_order_items.id',
        'purchase_orders.branch_id -> branches.id',
        'purchase_orders.created_by -> employees.id',
        'purchase_orders.location_id -> locations.id',
        'purchase_orders.request_id -> purchase_requests.id',
        'purchase_orders.supplier_id -> suppliers.id',
        'purchase_orders.updated_by -> employees.id',
        'purchase_orders.workflow_instance_id -> workflow_instances.id',
        'purchase_request_items.product_id -> products.id',
        'purchase_request_items.purchase_order_item_id -> purchase_order_items.id',
        'purchase_request_items.request_id -> purchase_requests.id (cascade)',
        'purchase_request_items.supplier_id -> suppliers.id',
        'purchase_request_items.unit_id -> units.id',
        'purchase_requests.branch_id -> branches.id',
        'purchase_requests.created_by -> employees.id',
        'purchase_requests.location_id -> locations.id',
        'purchase_requests.requested_by -> employees.id',
        'purchase_requests.updated_by -> employees.id',
        'purchase_requests.workflow_instance_id -> workflow_instances.id',
        'suppliers.created_by -> employees.id',
        'suppliers.updated_by -> employees.id',
    ]);
});

it('cascades the two documented deletes: order lines on their order, request lines on their request', function () {
    puSchemaApply();
    $connection = DB::connection(puSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $connection->table('purchase_orders')->insert(puSchemaOrder('ordC'));
    $connection->table('purchase_order_items')->insert(puSchemaOrderItem('oiC', 'ordC', 1));
    $connection->table('purchase_requests')->insert(puSchemaRequest('reqC'));
    $connection->table('purchase_request_items')->insert(puSchemaRequestItem('riC', 'reqC', 1));

    // A cascade is enforced at delete time, so the keys go back on for it.
    $connection->statement('pragma foreign_keys = on');

    $connection->table('purchase_orders')->where('id', puSchemaId('ordC'))->delete();
    $connection->table('purchase_requests')->where('id', puSchemaId('reqC'))->delete();

    expect($connection->table('purchase_order_items')->count())->toBe(0)
        ->and($connection->table('purchase_request_items')->count())->toBe(0);
});

it('refuses to delete a supplier an order references, a product a line references, or an item an application references', function () {
    puSchemaApply();
    $connection = DB::connection(puSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $connection->table('suppliers')->insert(puSchemaSupplier('sup1'));
    $connection->table('purchase_orders')->insert(puSchemaOrder('ordR'));
    $connection->table('purchase_order_items')->insert(puSchemaOrderItem('oiR', 'ordR', 1));
    $connection->table('purchase_order_receipt_applications')->insert(puSchemaApplication('appR', 'ordR', 'oiR'));

    $connection->statement('pragma foreign_keys = on');

    expect(fn () => $connection->table('suppliers')->where('id', puSchemaId('sup1'))->delete())->toThrow(QueryException::class)
        ->and(fn () => $connection->table('purchase_order_items')->where('id', puSchemaId('oiR'))->delete())->toThrow(QueryException::class)
        ->and(fn () => $connection->table('purchase_orders')->where('id', puSchemaId('ordR'))->delete())->toThrow(QueryException::class);

    expect($connection->table('suppliers')->count())->toBe(1)
        ->and($connection->table('purchase_order_items')->count())->toBe(1)
        ->and($connection->table('purchase_order_receipt_applications')->count())->toBe(1);
});

it('refuses to delete a product a purchase line references', function () {
    puSchemaApply();
    $connection = DB::connection(puSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $connection->table('products')->insert(puSchemaProductRow('prod1'));
    $connection->table('purchase_orders')->insert(puSchemaOrder('ordP'));
    $connection->table('purchase_order_items')->insert(puSchemaOrderItem('oiP', 'ordP', 1));

    $connection->statement('pragma foreign_keys = on');

    expect(fn () => $connection->table('products')->where('id', puSchemaId('prod1'))->delete())->toThrow(QueryException::class);
});

/**
 * A products row with every column given, so no default is relied on (foreign
 * keys are off when it is inserted, so its category and units need no rows).
 *
 * @return array<string, mixed>
 */
function puSchemaProductRow(string $label): array
{
    return [
        'id' => puSchemaId($label), 'created_at' => PU_SCHEMA_NOW, 'updated_at' => PU_SCHEMA_NOW, 'deleted_at' => null,
        'created_by' => null, 'updated_by' => null, 'type' => 'simple', 'parent_id' => null, 'sku' => 'SKU-'.$label,
        'name' => 'Product '.$label, 'short_name' => null, 'description' => null, 'category_id' => puSchemaId('cat1'),
        'brand_id' => null, 'stock_unit_id' => puSchemaId('unit1'), 'purchase_unit_id' => null, 'sales_unit_id' => null,
        'cost_price' => '0.0000', 'selling_price' => '0.0000', 'min_selling_price' => null, 'msrp' => null,
        'track_stock' => true, 'tracking_mode' => 'quantity', 'is_weighable' => false, 'price_mode' => 'per_unit',
        'plu_code' => null, 'shelf_life_days' => null, 'is_sellable' => true, 'is_purchasable' => true, 'is_active' => true,
        'allow_price_override' => false, 'allow_discount' => true, 'weight' => null, 'dimensions' => null,
        'variant_options' => null, 'variant_values' => null, 'image_path' => null, 'sort_order' => 0, 'version' => 1,
    ];
}

it('runs cleanly twice as a no-op, and no Purchasing migration declares a down()', function () {
    puSchemaApply();

    $before = Schema::connection(puSchemaConnection())->getTableListing();

    expect(Artisan::call('migrate', [
        '--database' => puSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    expect(Schema::connection(puSchemaConnection())->getTableListing())->toBe($before)
        ->and(count(puSchemaFiles()))->toBe(6);

    foreach (puSchemaFiles() as $file) {
        expect(file_exists($file))->toBeTrue()
            ->and(file_get_contents($file))->not->toContain('function down(');
    }
});

it('reports no SourceRules violation across the six Purchasing migrations, and each is an expand file', function () {
    $violations = [];

    foreach (puSchemaFiles() as $file) {
        expect(file_exists($file))->toBeTrue();

        $source = (string) file_get_contents($file);
        $violations = array_merge($violations, SourceRules::inspect($source, MigrationTree::Tenant));

        expect($source)->toContain('@phase expand')
            ->and($source)->not->toContain('@phase contract');
    }

    expect($violations)->toBe([]);
});

it('catalogues the six as consecutive expand rows, none destructive and none offline (looked up by name, never by number)', function () {
    $set = MigrationSet::baseline(migrationTreeDirectory(MigrationTree::Tenant));

    $versions = [];
    $rows = [];

    foreach (puSchemaMigrations() as $migration) {
        $version = $set->versionOf($migration);

        expect($version)->not->toBeNull();

        $entry = $set->entryFor((int) $version);

        $versions[] = $version;
        $rows[$migration] = [$entry->phase, $entry->isDestructive, $entry->requiresOffline];
    }

    $expected = [];

    foreach (puSchemaMigrations() as $migration) {
        $expected[$migration] = [MigrationPhase::Expand, false, false];
    }

    expect($rows)->toBe($expected)
        ->and($versions)->toBe(range($versions[0], $versions[0] + 5));
});
