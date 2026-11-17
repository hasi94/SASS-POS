<?php

namespace Flexipos\Sales\Tests\Schema;

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
| The eight Sales tables, applied to a SQLite stand-in (T-030 plan step 6,
| the T-028 and T-029 shape)
|--------------------------------------------------------------------------
|
| The schema halves of AC-1 to AC-37, AC-43 and AC-46, and D-33/D-44. Each
| table's sorted column list is asserted EXACTLY, with its nullable subset,
| so the absence of every Finance-owned column (journal_entry_id, ar_document_id,
| financial_account_id and the others D-33/F-8 name) is proved by equality and
| not by a list of things that are not there - the explicit not-contain
| assertions below are a second, louder statement of the same fact.
|
| No lengths or precisions are asserted here: SQLite reports bare types, and
| TenantDecimalPrecisionTest handles those statically.
|
| Rows are inserted with foreign keys OFF where a case is about a unique key
| and not a parent, so a double-insert proof does not need a product, a tax
| group and a unit behind every line; the cascade cases turn them on for the
| delete, which is when SQLite enforces a cascade.
*/

function salesSchemaConnection(): string
{
    return 'sales_schema_stand_in';
}

function salesSchemaApply(): void
{
    config()->set('database.connections.'.salesSchemaConnection(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge(salesSchemaConnection());

    expect(Artisan::call('migrate', [
        '--database' => salesSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * @return list<string>
 */
function salesSchemaColumns(string $table): array
{
    $names = array_column(Schema::connection(salesSchemaConnection())->getColumns($table), 'name');
    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function salesSchemaNullable(string $table): array
{
    $names = array_column(
        array_filter(Schema::connection(salesSchemaConnection())->getColumns($table), static fn (array $column): bool => $column['nullable']),
        'name'
    );
    sort($names);

    return $names;
}

/**
 * Which of the named columns a table has, in the order given: the absence
 * assertions' collector. A negated multi-needle toContain() passes when EITHER
 * needle is missing (QaAssertionShapeGuardTest), so absence is asserted as
 * "this list is empty" instead.
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function salesSchemaPresent(string $table, array $names): array
{
    return array_values(array_filter($names, static fn (string $name): bool => in_array($name, salesSchemaColumns($table), true)));
}

function salesSchemaId(string $label): string
{
    return str_pad($label, 26, 'x');
}

const SALES_SCHEMA_NOW = '2026-11-10 00:00:00.000';

/**
 * A minimal shift row.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function salesSchemaShift(string $label, array $overrides = []): array
{
    return array_merge([
        'id' => salesSchemaId($label), 'created_at' => SALES_SCHEMA_NOW, 'updated_at' => SALES_SCHEMA_NOW,
        'terminal_id' => salesSchemaId('term1'), 'branch_id' => salesSchemaId('br1'), 'employee_id' => salesSchemaId('emp1'),
        'number' => 'SH-'.$label, 'status' => 'open', 'opened_at' => SALES_SCHEMA_NOW, 'opening_cash' => '100.0000',
    ], $overrides);
}

/**
 * A minimal sale row (the money columns have no default except balance_due).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function salesSchemaSale(string $label, array $overrides = []): array
{
    return array_merge([
        'id' => salesSchemaId($label), 'created_at' => SALES_SCHEMA_NOW, 'updated_at' => SALES_SCHEMA_NOW,
        'branch_id' => salesSchemaId('br1'), 'location_id' => salesSchemaId('loc1'), 'employee_id' => salesSchemaId('emp1'),
        'type' => 'sale', 'status' => 'draft', 'business_date' => '2026-11-10', 'business_date_source' => 'server', 'currency' => 'USD',
        'subtotal' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'service_charge' => '0.0000',
        'rounding' => '0.0000', 'total' => '0.0000', 'paid_total' => '0.0000', 'change_due' => '0.0000',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function salesSchemaItem(string $label, string $saleLabel, int $lineNo, array $overrides = []): array
{
    return array_merge([
        'id' => salesSchemaId($label), 'sale_id' => salesSchemaId($saleLabel), 'line_no' => $lineNo,
        'product_id' => salesSchemaId('prod1'), 'product_name' => 'Cola', 'unit_code' => 'each', 'unit_id' => salesSchemaId('unit1'),
        'quantity' => '1.0000', 'unit_factor' => '1.000000', 'base_quantity' => '1.0000', 'list_price' => '5.0000',
        'unit_price' => '5.0000', 'subtotal' => '5.0000', 'discount_amount' => '0.0000', 'tax_group_id' => salesSchemaId('tg1'),
        'tax_amount' => '0.0000', 'tax_breakdown' => '{}', 'line_total' => '5.0000',
    ], $overrides);
}

it('creates payment_methods with exactly the documented columns, no financial_account_id, gateway or settings (AC-16, D-7, D-33)', function () {
    salesSchemaApply();

    expect(salesSchemaColumns('payment_methods'))->toBe([
        'allows_change', 'allows_partial', 'allows_refund', 'code', 'created_at', 'deleted_at', 'id', 'is_active', 'name',
        'opens_drawer', 'requires_reference', 'rounding_rule', 'sort_order', 'surcharge_percent', 'type', 'updated_at', 'version',
    ])->and(salesSchemaNullable('payment_methods'))->toBe(['deleted_at', 'rounding_rule', 'surcharge_percent'])
        ->and(salesSchemaPresent('payment_methods', ['financial_account_id', 'gateway', 'settings']))->toBe([]);
});

it('creates cash_shifts with exactly the documented columns, no journal_entry_id (AC-32, AC-34, D-6)', function () {
    salesSchemaApply();

    expect(salesSchemaColumns('cash_shifts'))->toBe([
        'branch_id', 'client_created_at', 'client_seq', 'closed_at', 'closed_by', 'counted_cash', 'created_at', 'device_id',
        'discounts', 'employee_id', 'expected_cash', 'gross', 'id', 'net', 'notes', 'number', 'open_terminal_key', 'opened_at',
        'opening_cash', 'origin', 'reconciled_by', 'refunds_count', 'sales_count', 'status', 'sync_status', 'synced_at', 'tax',
        'terminal_id', 'totals_by_method', 'updated_at', 'variance',
    ])->and(salesSchemaNullable('cash_shifts'))->toBe([
        'client_created_at', 'client_seq', 'closed_at', 'closed_by', 'counted_cash', 'device_id', 'discounts', 'expected_cash',
        'gross', 'net', 'notes', 'open_terminal_key', 'reconciled_by', 'refunds_count', 'sales_count', 'synced_at', 'tax',
        'totals_by_method', 'variance',
    ])->and(salesSchemaPresent('cash_shifts', ['journal_entry_id']))->toBe([]);
});

it('creates cash_movements with exactly the documented columns, no financial_account_id (AC-33, D-6)', function () {
    salesSchemaApply();

    expect(salesSchemaColumns('cash_movements'))->toBe([
        'amount', 'approved_by', 'client_created_at', 'client_seq', 'created_at', 'device_id', 'employee_id', 'id', 'origin',
        'reason', 'reference', 'shift_id', 'sync_status', 'synced_at', 'terminal_id', 'type',
    ])->and(salesSchemaNullable('cash_movements'))->toBe([
        'approved_by', 'client_created_at', 'client_seq', 'device_id', 'reference', 'synced_at', 'terminal_id',
    ])->and(salesSchemaPresent('cash_movements', ['financial_account_id']))->toBe([]);
});

it('creates approval_requests with exactly the documented columns, consumed_at included (AC-23, AC-24, AC-46, D-44)', function () {
    salesSchemaApply();

    expect(salesSchemaColumns('approval_requests'))->toBe([
        'branch_id', 'client_created_at', 'client_seq', 'consumed_at', 'created_at', 'decided_at', 'decided_by',
        'decision_method', 'device_id', 'id', 'origin', 'payload', 'plugin_name', 'reason', 'reference_id',
        'reference_type', 'requested_by', 'status', 'sync_status', 'synced_at', 'terminal_id', 'type', 'updated_at',
    ])->and(salesSchemaNullable('approval_requests'))->toBe([
        'client_created_at', 'client_seq', 'consumed_at', 'decided_at', 'decided_by', 'decision_method', 'device_id',
        'payload', 'plugin_name', 'reason', 'reference_id', 'synced_at', 'terminal_id',
    ]);
});

it('creates sales with exactly the documented columns, none of the five Finance and order columns (AC-1, AC-43, D-33)', function () {
    salesSchemaApply();

    expect(salesSchemaColumns('sales'))->toBe([
        'approval_request_id', 'balance_due', 'bill_discount_id', 'bill_discount_value', 'branch_id', 'business_date',
        'business_date_source', 'change_due', 'client_created_at', 'client_seq', 'completed_at', 'cost_total', 'created_at',
        'credit_checked', 'currency', 'customer_id', 'customer_name', 'customer_phone', 'customer_tax_no', 'device_id',
        'discount_reason', 'discount_total', 'employee_id', 'exchange_rate', 'id', 'location_id', 'notes', 'number', 'origin',
        'paid_total', 'price_list_id', 'receipt_print_count', 'reference_sale_id', 'rounding', 'service_charge', 'shift_id',
        'status', 'stock_applied', 'subtotal', 'sync_status', 'synced_at', 'tax_total', 'terminal_id', 'total', 'type',
        'updated_at', 'void_reason', 'voided_at', 'voided_by',
    ])->and(salesSchemaNullable('sales'))->toBe([
        'approval_request_id', 'bill_discount_id', 'bill_discount_value', 'client_created_at', 'client_seq', 'completed_at',
        'cost_total', 'customer_id', 'customer_name', 'customer_phone', 'customer_tax_no', 'device_id', 'discount_reason',
        'notes', 'number', 'price_list_id', 'reference_sale_id', 'shift_id', 'synced_at', 'terminal_id', 'void_reason',
        'voided_at', 'voided_by',
    ])->and(salesSchemaPresent('sales', ['journal_entry_id', 'ar_document_id', 'sales_order_id', 'order_id', 'custom_fields']))->toBe([]);
});

it('creates sale_items with exactly the documented columns, subtotal included, no batch, serial or kitchen column (AC-2, AC-9, AC-43, D-33, D-44)', function () {
    salesSchemaApply();

    expect(salesSchemaColumns('sale_items'))->toBe([
        'base_quantity', 'brand_id', 'category_id', 'discount_amount', 'discount_id', 'discount_percent', 'employee_id', 'id',
        'line_cost', 'line_no', 'line_total', 'list_price', 'modifiers', 'notes', 'price_from_label', 'price_overridden_by',
        'product_id', 'product_name', 'quantity', 'refund_of_item_id', 'refunded_quantity', 'sale_id', 'sku', 'sort_order',
        'subtotal', 'tax_amount', 'tax_breakdown', 'tax_group_id', 'unit_code', 'unit_cost', 'unit_factor', 'unit_id',
        'unit_price',
    ])->and(salesSchemaNullable('sale_items'))->toBe([
        'brand_id', 'category_id', 'discount_id', 'discount_percent', 'employee_id', 'line_cost', 'modifiers', 'notes',
        'price_overridden_by', 'refund_of_item_id', 'sku', 'unit_cost',
    ])->and(salesSchemaPresent('sale_items', ['batch_id', 'serial_id', 'kitchen_station_id']))->toBe([]);
});

it('creates sale_tenders with exactly the documented columns, no gateway_transaction_id or payment_id (AC-11, AC-43, D-7)', function () {
    salesSchemaApply();

    expect(salesSchemaColumns('sale_tenders'))->toBe([
        'amount', 'change_given', 'id', 'method_code', 'method_type', 'paid_at', 'payment_method_id', 'payment_status',
        'reference', 'sale_id', 'tendered',
    ])->and(salesSchemaNullable('sale_tenders'))->toBe(['reference', 'tendered'])
        ->and(salesSchemaPresent('sale_tenders', ['gateway_transaction_id', 'payment_id']))->toBe([]);
});

it('creates sale_holds with exactly the documented columns, all required (AC-17 to AC-19, AC-46, D-44)', function () {
    salesSchemaApply();

    expect(salesSchemaColumns('sale_holds'))->toBe([
        'action', 'created_at', 'employee_id', 'from_status', 'id', 'sale_id', 'terminal_id', 'to_status',
    ])->and(salesSchemaNullable('sale_holds'))->toBe([]);
});

it('holds the one-open-shift-per-terminal backstop: two open shifts on one terminal refuse, an open and a closed accept (D-22)', function () {
    salesSchemaApply();
    $connection = DB::connection(salesSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $connection->table('cash_shifts')->insert(salesSchemaShift('shift1'));

    expect(fn () => $connection->table('cash_shifts')->insert(salesSchemaShift('shift2')))->toThrow(QueryException::class);

    // A closed shift on the same terminal coexists with the open one, and so do several.
    $connection->table('cash_shifts')->insert(salesSchemaShift('shift3', ['status' => 'closed']));
    $connection->table('cash_shifts')->insert(salesSchemaShift('shift4', ['status' => 'closed']));
    // Another terminal may have its own open shift.
    $connection->table('cash_shifts')->insert(salesSchemaShift('shift5', ['terminal_id' => salesSchemaId('term2')]));

    expect($connection->table('cash_shifts')->count())->toBe(4)
        ->and($connection->table('cash_shifts')->where('id', salesSchemaId('shift1'))->value('open_terminal_key'))->toBe(salesSchemaId('term1'))
        ->and($connection->table('cash_shifts')->where('id', salesSchemaId('shift3'))->value('open_terminal_key'))->toBeNull();

    // Closing the open shift frees the key.
    $connection->table('cash_shifts')->where('id', salesSchemaId('shift1'))->update(['status' => 'closed']);
    $connection->table('cash_shifts')->insert(salesSchemaShift('shift6'));

    expect($connection->table('cash_shifts')->where('terminal_id', salesSchemaId('term1'))->where('status', 'open')->count())->toBe(1);
});

it('holds sales.number unique, with two NULL numbers accepted', function () {
    salesSchemaApply();
    $connection = DB::connection(salesSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $connection->table('sales')->insert(salesSchemaSale('sale1'));
    $connection->table('sales')->insert(salesSchemaSale('sale2'));

    expect($connection->table('sales')->whereNull('number')->count())->toBe(2);

    $connection->table('sales')->insert(salesSchemaSale('sale3', ['number' => 'S000001']));

    expect(fn () => $connection->table('sales')->insert(salesSchemaSale('sale4', ['number' => 'S000001', 'type' => 'refund'])))
        ->toThrow(QueryException::class);
});

it('holds payment_methods.code, cash_shifts.number and (sale_id, line_no) unique', function () {
    salesSchemaApply();
    $connection = DB::connection(salesSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $method = static fn (string $label, string $code): array => [
        'id' => salesSchemaId($label), 'created_at' => SALES_SCHEMA_NOW, 'updated_at' => SALES_SCHEMA_NOW,
        'code' => $code, 'name' => 'Method '.$code, 'type' => 'cash',
    ];

    $connection->table('payment_methods')->insert($method('pm1', 'cash'));

    expect(fn () => $connection->table('payment_methods')->insert($method('pm2', 'cash')))->toThrow(QueryException::class);

    $connection->table('cash_shifts')->insert(salesSchemaShift('shiftA', ['status' => 'closed', 'number' => 'SH000001']));

    expect(fn () => $connection->table('cash_shifts')->insert(salesSchemaShift('shiftB', ['status' => 'closed', 'number' => 'SH000001'])))
        ->toThrow(QueryException::class);

    $connection->table('sales')->insert(salesSchemaSale('saleA'));
    $connection->table('sale_items')->insert(salesSchemaItem('item1', 'saleA', 1));
    $connection->table('sale_items')->insert(salesSchemaItem('item2', 'saleA', 2));

    expect(fn () => $connection->table('sale_items')->insert(salesSchemaItem('item3', 'saleA', 1)))->toThrow(QueryException::class);

    // The same line number on another sale is a different key.
    $connection->table('sales')->insert(salesSchemaSale('saleB'));
    $connection->table('sale_items')->insert(salesSchemaItem('item4', 'saleB', 1));

    expect($connection->table('sale_items')->count())->toBe(3);
});

it('holds exactly the 36 documented foreign keys on the new tables', function () {
    salesSchemaApply();

    $tables = ['approval_requests', 'cash_movements', 'cash_shifts', 'payment_methods', 'sale_holds', 'sale_items', 'sale_tenders', 'sales'];
    $keys = [];

    foreach ($tables as $table) {
        foreach (DB::connection(salesSchemaConnection())->select('pragma foreign_key_list("'.$table.'")') as $key) {
            $key = (array) $key;
            $keys[] = $table.'.'.$key['from'].' -> '.$key['table'].'.'.$key['to'];
        }
    }

    sort($keys);

    expect($keys)->toBe([
        'approval_requests.branch_id -> branches.id',
        'approval_requests.decided_by -> employees.id',
        'approval_requests.requested_by -> employees.id',
        'approval_requests.terminal_id -> terminals.id',
        'cash_movements.approved_by -> employees.id',
        'cash_movements.employee_id -> employees.id',
        'cash_movements.shift_id -> cash_shifts.id',
        'cash_shifts.branch_id -> branches.id',
        'cash_shifts.closed_by -> employees.id',
        'cash_shifts.employee_id -> employees.id',
        'cash_shifts.reconciled_by -> employees.id',
        'cash_shifts.terminal_id -> terminals.id',
        'sale_holds.employee_id -> employees.id',
        'sale_holds.sale_id -> sales.id',
        'sale_holds.terminal_id -> terminals.id',
        'sale_items.discount_id -> discounts.id',
        'sale_items.employee_id -> employees.id',
        'sale_items.price_overridden_by -> employees.id',
        'sale_items.product_id -> products.id',
        'sale_items.refund_of_item_id -> sale_items.id',
        'sale_items.sale_id -> sales.id',
        'sale_items.tax_group_id -> tax_groups.id',
        'sale_items.unit_id -> units.id',
        'sale_tenders.payment_method_id -> payment_methods.id',
        'sale_tenders.sale_id -> sales.id',
        'sales.approval_request_id -> approval_requests.id',
        'sales.bill_discount_id -> discounts.id',
        'sales.branch_id -> branches.id',
        'sales.customer_id -> customers.id',
        'sales.employee_id -> employees.id',
        'sales.location_id -> locations.id',
        'sales.price_list_id -> price_lists.id',
        'sales.reference_sale_id -> sales.id',
        'sales.shift_id -> cash_shifts.id',
        'sales.terminal_id -> terminals.id',
        'sales.voided_by -> employees.id',
    ]);
});

it('cascades the four documented deletes: movements on their shift; items, tenders and holds on their sale', function () {
    salesSchemaApply();
    $connection = DB::connection(salesSchemaConnection());
    $connection->statement('pragma foreign_keys = off');

    $connection->table('cash_shifts')->insert(salesSchemaShift('shiftC', ['status' => 'closed']));
    $connection->table('cash_movements')->insert([
        'id' => salesSchemaId('mov1'), 'shift_id' => salesSchemaId('shiftC'), 'type' => 'drop', 'amount' => '10.0000',
        'reason' => 'drop', 'employee_id' => salesSchemaId('emp1'), 'created_at' => SALES_SCHEMA_NOW,
    ]);

    $connection->table('sales')->insert(salesSchemaSale('saleC'));
    $connection->table('sale_items')->insert(salesSchemaItem('itemC', 'saleC', 1));
    $connection->table('sale_tenders')->insert([
        'id' => salesSchemaId('tenC'), 'sale_id' => salesSchemaId('saleC'), 'payment_method_id' => salesSchemaId('pm1'),
        'method_code' => 'cash', 'method_type' => 'cash', 'amount' => '5.0000', 'paid_at' => SALES_SCHEMA_NOW,
    ]);
    $connection->table('sale_holds')->insert([
        'id' => salesSchemaId('holdC'), 'sale_id' => salesSchemaId('saleC'), 'action' => 'hold', 'from_status' => 'draft',
        'to_status' => 'held', 'terminal_id' => salesSchemaId('term1'), 'employee_id' => salesSchemaId('emp1'), 'created_at' => SALES_SCHEMA_NOW,
    ]);

    // A cascade is enforced at delete time, so the keys go back on for it.
    $connection->statement('pragma foreign_keys = on');

    $connection->table('sales')->where('id', salesSchemaId('saleC'))->delete();

    expect($connection->table('sale_items')->count())->toBe(0)
        ->and($connection->table('sale_tenders')->count())->toBe(0)
        ->and($connection->table('sale_holds')->count())->toBe(0)
        ->and($connection->table('cash_movements')->count())->toBe(1);

    $connection->table('cash_shifts')->where('id', salesSchemaId('shiftC'))->delete();

    expect($connection->table('cash_movements')->count())->toBe(0);
});

it('runs cleanly twice as a no-op, and no Sales migration declares a down()', function () {
    salesSchemaApply();

    $before = Schema::connection(salesSchemaConnection())->getTableListing();

    expect(Artisan::call('migrate', [
        '--database' => salesSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    expect(Schema::connection(salesSchemaConnection())->getTableListing())->toBe($before);

    $files = glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_10_0030*.php') ?: [];

    expect(count($files))->toBeGreaterThanOrEqual(8);

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('function down(');
    }
});

it('reports no SourceRules violation across the Sales migrations', function () {
    $files = glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_10_0030*.php') ?: [];
    $violations = [];

    expect(count($files))->toBeGreaterThanOrEqual(8);

    foreach ($files as $file) {
        $violations = array_merge($violations, SourceRules::inspect((string) file_get_contents($file), MigrationTree::Tenant));
    }

    expect($violations)->toBe([]);
});
