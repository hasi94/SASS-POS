<?php

namespace Flexipos\Pricing\Tests\Schema;

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
| The nine pricing tables, applied to a SQLite stand-in (T-029 plan step 2,
| the T-027/T-028 shape)
|--------------------------------------------------------------------------
|
| AC-4 to AC-6, AC-8, AC-9, AC-12 to AC-14 and AC-19's schema halves. Each
| table's column list is asserted exactly, which is also what proves the
| ABSENCE of the three Finance-dependent account columns (F-17, D-7): a
| column list that names every column cannot also hide one.
|
| No lengths or precisions are asserted here: SQLite reports bare types, and
| TenantDecimalPrecisionTest handles those statically.
|
| The tree's backfill migration seeds one default `STANDARD` tax group even
| into an empty database (D-6: it must cover a freshly provisioned tenant), so
| every test below that needs a default group of its own starts from that fact.
*/

function pricingSchemaConnection(): string
{
    return 'pricing_schema_stand_in';
}

function pricingApplyTenantTree(): void
{
    config()->set('database.connections.'.pricingSchemaConnection(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge(pricingSchemaConnection());

    expect(Artisan::call('migrate', [
        '--database' => pricingSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * @return list<string>
 */
function pricingSchemaColumns(string $table): array
{
    $names = array_column(Schema::connection(pricingSchemaConnection())->getColumns($table), 'name');
    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function pricingSchemaNullableColumns(string $table): array
{
    $names = array_column(
        array_filter(Schema::connection(pricingSchemaConnection())->getColumns($table), static fn (array $column): bool => $column['nullable']),
        'name'
    );
    sort($names);

    return $names;
}

function pricingSchemaNow(): string
{
    return '2026-11-10 00:00:00.000';
}

function pricingSchemaId(string $seed): string
{
    return str_pad($seed, 26, 'x');
}

/**
 * Minimal seed rows this file's inserts build on: a unit, a second unit, a
 * category, a product, an authority, a tax and a price list.
 *
 * @return array{each: string, case: string, category: string, product: string, authority: string, tax: string, list: string}
 */
function pricingSchemaSeed(): array
{
    $connection = DB::connection(pricingSchemaConnection());
    $now = pricingSchemaNow();

    $each = pricingSchemaId('each');
    $case = pricingSchemaId('case');
    $connection->table('units')->insert([
        ['id' => $each, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count', 'precision' => 0, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $case, 'code' => 'case', 'name' => 'Case', 'dimension' => 'count', 'precision' => 0, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $category = pricingSchemaId('cat1');
    $connection->table('categories')->insert([
        'id' => $category, 'created_at' => $now, 'updated_at' => $now,
        'code' => 'c1', 'name' => 'Cat 1', 'path' => $category, 'depth' => 0,
    ]);

    $product = pricingSchemaId('prod1');
    $connection->table('products')->insert([
        'id' => $product, 'created_at' => $now, 'updated_at' => $now,
        'type' => 'simple', 'sku' => 'sku1', 'name' => 'Product 1',
        'category_id' => $category, 'stock_unit_id' => $each,
    ]);

    $authority = pricingSchemaId('auth1');
    $connection->table('tax_authorities')->insert([
        'id' => $authority, 'created_at' => $now, 'updated_at' => $now, 'code' => 'auth1', 'name' => 'Authority 1',
    ]);

    $tax = pricingSchemaId('tax1');
    $connection->table('taxes')->insert([
        'id' => $tax, 'created_at' => $now, 'updated_at' => $now, 'authority_id' => $authority,
        'code' => 'vat', 'name' => 'VAT', 'type' => 'percentage', 'applies_to' => 'both',
    ]);

    $list = pricingSchemaId('list1');
    $connection->table('price_lists')->insert([
        'id' => $list, 'created_at' => $now, 'updated_at' => $now,
        'code' => 'retail', 'name' => 'Retail', 'type' => 'standard', 'currency' => 'USD',
    ]);

    return ['each' => $each, 'case' => $case, 'category' => $category, 'product' => $product, 'authority' => $authority, 'tax' => $tax, 'list' => $list];
}

it('creates tax_authorities with exactly the documented columns, and none of the two deferred account columns (F-17)', function () {
    pricingApplyTenantTree();

    expect(pricingSchemaColumns('tax_authorities'))->toBe([
        'code', 'country', 'created_at', 'filing_frequency', 'id', 'name', 'registration_no', 'updated_at',
    ])->and(pricingSchemaNullableColumns('tax_authorities'))->toBe(['country', 'filing_frequency', 'registration_no'])
        ->and(pricingSchemaColumns('tax_authorities'))->not->toContain('payable_account_id')
        ->and(pricingSchemaColumns('tax_authorities'))->not->toContain('receivable_account_id');
});

it('creates taxes with exactly the documented columns', function () {
    pricingApplyTenantTree();

    expect(pricingSchemaColumns('taxes'))->toBe([
        'applies_to', 'authority_id', 'code', 'created_at', 'deleted_at', 'id', 'is_active', 'is_compound',
        'is_recoverable', 'is_withholding', 'name', 'tax_code', 'type', 'updated_at',
    ])->and(pricingSchemaNullableColumns('taxes'))->toBe(['deleted_at', 'tax_code']);
});

it('creates tax_rates with exactly the documented columns', function () {
    pricingApplyTenantTree();

    expect(pricingSchemaColumns('tax_rates'))->toBe([
        'created_at', 'effective_from', 'effective_to', 'id', 'note', 'rate', 'tax_id', 'updated_at',
    ])->and(pricingSchemaNullableColumns('tax_rates'))->toBe(['effective_to', 'note']);
});

it('creates tax_groups with exactly the documented columns and no deleted_at (F-6, D-22)', function () {
    pricingApplyTenantTree();

    expect(pricingSchemaColumns('tax_groups'))->toBe([
        'code', 'created_at', 'default_marker', 'id', 'is_active', 'is_default', 'is_inclusive', 'name', 'updated_at',
    ])->and(pricingSchemaNullableColumns('tax_groups'))->toBe(['default_marker'])
        ->and(pricingSchemaColumns('tax_groups'))->not->toContain('deleted_at');
});

it('creates tax_group_taxes with exactly the documented columns and no timestamps', function () {
    pricingApplyTenantTree();

    expect(pricingSchemaColumns('tax_group_taxes'))->toBe(['sequence', 'tax_group_id', 'tax_id'])
        ->and(pricingSchemaNullableColumns('tax_group_taxes'))->toBe([]);
});

it('creates price_lists with exactly the documented columns', function () {
    pricingApplyTenantTree();

    expect(pricingSchemaColumns('price_lists'))->toBe([
        'code', 'created_at', 'currency', 'deleted_at', 'ends_at', 'id', 'is_active', 'name', 'priority',
        'starts_at', 'type', 'updated_at',
    ])->and(pricingSchemaNullableColumns('price_lists'))->toBe(['deleted_at', 'ends_at', 'starts_at']);
});

it('creates price_list_items with exactly the documented columns, no timestamps and no soft delete', function () {
    pricingApplyTenantTree();

    expect(pricingSchemaColumns('price_list_items'))->toBe([
        'discount_percent', 'id', 'min_qty', 'price', 'price_list_id', 'product_id', 'unit_id', 'unit_key',
    ])->and(pricingSchemaNullableColumns('price_list_items'))->toBe(['discount_percent', 'unit_id', 'unit_key']);
});

it('creates price_list_assignments with exactly the documented columns', function () {
    pricingApplyTenantTree();

    expect(pricingSchemaColumns('price_list_assignments'))->toBe([
        'id', 'price_list_id', 'priority', 'scope_id', 'scope_type',
    ])->and(pricingSchemaNullableColumns('price_list_assignments'))->toBe([]);
});

it('creates discounts with exactly the documented columns, and no sales_discount_account_id (F-17)', function () {
    pricingApplyTenantTree();

    expect(pricingSchemaColumns('discounts'))->toBe(pricingSortedList([
        'applies_to', 'approval_threshold', 'code', 'created_at', 'created_by', 'deleted_at', 'id', 'is_active',
        'max_discount', 'min_amount', 'name', 'requires_approval', 'scope', 'starts_at', 'ends_at', 'type',
        'updated_at', 'updated_by', 'usage_limit', 'used_count', 'value', 'workflow_definition_id',
    ]))->and(pricingSchemaNullableColumns('discounts'))->toBe(pricingSortedList([
        'applies_to', 'approval_threshold', 'created_by', 'deleted_at', 'ends_at', 'max_discount', 'min_amount',
        'starts_at', 'updated_by', 'usage_limit', 'workflow_definition_id',
    ]))->and(pricingSchemaColumns('discounts'))->not->toContain('sales_discount_account_id');
});

/**
 * @param  list<string>  $names
 * @return list<string>
 */
function pricingSortedList(array $names): array
{
    sort($names);

    return $names;
}

it('holds every code unique key', function () {
    pricingApplyTenantTree();
    $connection = DB::connection(pricingSchemaConnection());
    $now = pricingSchemaNow();
    $seed = pricingSchemaSeed();

    $rows = [
        'tax_authorities' => ['created_at' => $now, 'updated_at' => $now, 'code' => 'auth1', 'name' => 'Again'],
        'taxes' => ['created_at' => $now, 'updated_at' => $now, 'authority_id' => $seed['authority'], 'code' => 'vat', 'name' => 'Again', 'type' => 'percentage', 'applies_to' => 'both'],
        'price_lists' => ['created_at' => $now, 'updated_at' => $now, 'code' => 'retail', 'name' => 'Again', 'type' => 'standard', 'currency' => 'USD'],
    ];

    foreach ($rows as $table => $row) {
        expect(fn () => $connection->table($table)->insert(['id' => pricingSchemaId('dup'.$table)] + $row))
            ->toThrow(QueryException::class);
    }

    $connection->table('tax_groups')->insert([
        'id' => pricingSchemaId('grp1'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'reduced', 'name' => 'Reduced',
    ]);
    expect(fn () => $connection->table('tax_groups')->insert([
        'id' => pricingSchemaId('grp2'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'reduced', 'name' => 'Again',
    ]))->toThrow(QueryException::class);

    $connection->table('discounts')->insert([
        'id' => pricingSchemaId('disc1'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'ten', 'name' => 'Ten',
        'type' => 'percentage', 'value' => 10, 'scope' => 'item',
    ]);
    expect(fn () => $connection->table('discounts')->insert([
        'id' => pricingSchemaId('disc2'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'ten', 'name' => 'Again',
        'type' => 'percentage', 'value' => 10, 'scope' => 'item',
    ]))->toThrow(QueryException::class);
});

it('holds the tax_rates (tax_id, effective_from) unique key', function () {
    pricingApplyTenantTree();
    $connection = DB::connection(pricingSchemaConnection());
    $now = pricingSchemaNow();
    $seed = pricingSchemaSeed();

    $row = static fn (string $id, string $from): array => [
        'id' => pricingSchemaId($id), 'created_at' => $now, 'updated_at' => $now,
        'tax_id' => $seed['tax'], 'rate' => '15.0000', 'effective_from' => $from,
    ];

    $connection->table('tax_rates')->insert($row('rate1', '2024-01-01'));

    expect(fn () => $connection->table('tax_rates')->insert($row('rate2', '2024-01-01')))->toThrow(QueryException::class);

    // A different start date for the same tax is a different key.
    $connection->table('tax_rates')->insert($row('rate3', '2025-01-01'));

    expect($connection->table('tax_rates')->count())->toBe(2);
});

it('holds the one-default tax group backstop: a second default refuses, any number of non-defaults coexist', function () {
    pricingApplyTenantTree();
    $connection = DB::connection(pricingSchemaConnection());
    $now = pricingSchemaNow();

    // The backfill migration already seeded the tenant's one default group.
    expect($connection->table('tax_groups')->where('is_default', true)->count())->toBe(1);

    expect(fn () => $connection->table('tax_groups')->insert([
        'id' => pricingSchemaId('grpd2'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'default-b', 'name' => 'Default B', 'is_default' => true,
    ]))->toThrow(QueryException::class);

    foreach (['a', 'b', 'c'] as $suffix) {
        $connection->table('tax_groups')->insert([
            'id' => pricingSchemaId('grpn'.$suffix), 'created_at' => $now, 'updated_at' => $now, 'code' => 'plain-'.$suffix, 'name' => 'Plain '.$suffix,
        ]);
    }

    expect($connection->table('tax_groups')->where('is_default', true)->count())->toBe(1)
        ->and($connection->table('tax_groups')->count())->toBe(4);
});

it('holds the tax_group_taxes primary key and the (tax_group_id, sequence) unique key', function () {
    pricingApplyTenantTree();
    $connection = DB::connection(pricingSchemaConnection());
    $now = pricingSchemaNow();
    $seed = pricingSchemaSeed();

    $group = pricingSchemaId('grpm1');
    $connection->table('tax_groups')->insert(['id' => $group, 'created_at' => $now, 'updated_at' => $now, 'code' => 'm1', 'name' => 'M1']);

    $secondTax = pricingSchemaId('tax2');
    $connection->table('taxes')->insert([
        'id' => $secondTax, 'created_at' => $now, 'updated_at' => $now, 'authority_id' => $seed['authority'],
        'code' => 'levy', 'name' => 'Levy', 'type' => 'fixed', 'applies_to' => 'sale',
    ]);

    $connection->table('tax_group_taxes')->insert(['tax_group_id' => $group, 'tax_id' => $seed['tax'], 'sequence' => 1]);

    // The same tax twice in one group: the documented primary key.
    expect(fn () => $connection->table('tax_group_taxes')->insert(['tax_group_id' => $group, 'tax_id' => $seed['tax'], 'sequence' => 2]))
        ->toThrow(QueryException::class);

    // A second tax at an already-used sequence: the key this task adds.
    expect(fn () => $connection->table('tax_group_taxes')->insert(['tax_group_id' => $group, 'tax_id' => $secondTax, 'sequence' => 1]))
        ->toThrow(QueryException::class);

    $connection->table('tax_group_taxes')->insert(['tax_group_id' => $group, 'tax_id' => $secondTax, 'sequence' => 2]);

    expect($connection->table('tax_group_taxes')->where('tax_group_id', $group)->count())->toBe(2);
});

it('holds the price_list_items unit_key sentinel: two NULL-unit rows at one min_qty refuse, a NULL row and a unit row coexist', function () {
    pricingApplyTenantTree();
    $connection = DB::connection(pricingSchemaConnection());
    $seed = pricingSchemaSeed();

    $row = static fn (string $id, ?string $unit, string $minQty): array => [
        'id' => pricingSchemaId($id), 'price_list_id' => $seed['list'], 'product_id' => $seed['product'],
        'unit_id' => $unit, 'min_qty' => $minQty, 'price' => '7.5000',
    ];

    $connection->table('price_list_items')->insert($row('item1', null, '1.0000'));

    // The case the sentinel exists for: NULLs are distinct in a plain unique index.
    expect(fn () => $connection->table('price_list_items')->insert($row('item2', null, '1.0000')))
        ->toThrow(QueryException::class);

    // A NULL-unit row and a unit-matched row at the same min_qty are different keys.
    $connection->table('price_list_items')->insert($row('item3', $seed['case'], '1.0000'));

    // The same unit at a different min_qty is a volume break, not a duplicate.
    $connection->table('price_list_items')->insert($row('item4', $seed['case'], '2.0000'));

    // And a second unit-matched row at the same unit and min_qty is refused.
    expect(fn () => $connection->table('price_list_items')->insert($row('item5', $seed['case'], '1.0000')))
        ->toThrow(QueryException::class);

    expect($connection->table('price_list_items')->count())->toBe(3)
        ->and($connection->table('price_list_items')->whereNull('unit_id')->value('unit_key'))->toBe('-');
});

it('holds the price_list_assignments (scope_type, scope_id, price_list_id) unique key', function () {
    pricingApplyTenantTree();
    $connection = DB::connection(pricingSchemaConnection());
    $seed = pricingSchemaSeed();

    $row = static fn (string $id, string $type, string $scope): array => [
        'id' => pricingSchemaId($id), 'price_list_id' => $seed['list'], 'scope_type' => $type, 'scope_id' => pricingSchemaId($scope),
    ];

    $connection->table('price_list_assignments')->insert($row('as1', 'branch', 'scopeA'));

    expect(fn () => $connection->table('price_list_assignments')->insert($row('as2', 'branch', 'scopeA')))
        ->toThrow(QueryException::class);

    // The same id under another scope type is a different key (polymorphic scope_id, F-8).
    $connection->table('price_list_assignments')->insert($row('as3', 'customer', 'scopeA'));

    expect($connection->table('price_list_assignments')->count())->toBe(2);
});

it('holds exactly the 11 documented foreign keys on the nine new tables, restrict everywhere except the two cascades', function () {
    pricingApplyTenantTree();

    $tables = [
        'discounts', 'price_list_assignments', 'price_list_items', 'price_lists', 'tax_authorities',
        'tax_group_taxes', 'tax_groups', 'tax_rates', 'taxes',
    ];

    $keys = [];

    foreach ($tables as $table) {
        foreach (DB::connection(pricingSchemaConnection())->select('pragma foreign_key_list("'.$table.'")') as $key) {
            $key = (array) $key;
            $keys[] = $table.'.'.$key['from'].' -> '.$key['table'].'.'.$key['to'].' ['.$key['on_delete'].']';
        }
    }

    sort($keys);

    expect($keys)->toBe([
        'discounts.created_by -> employees.id [NO ACTION]',
        'discounts.updated_by -> employees.id [NO ACTION]',
        'discounts.workflow_definition_id -> workflow_definitions.id [NO ACTION]',
        'price_list_assignments.price_list_id -> price_lists.id [NO ACTION]',
        'price_list_items.price_list_id -> price_lists.id [CASCADE]',
        'price_list_items.product_id -> products.id [NO ACTION]',
        'price_list_items.unit_id -> units.id [NO ACTION]',
        'tax_group_taxes.tax_group_id -> tax_groups.id [CASCADE]',
        'tax_group_taxes.tax_id -> taxes.id [NO ACTION]',
        'tax_rates.tax_id -> taxes.id [NO ACTION]',
        'taxes.authority_id -> tax_authorities.id [NO ACTION]',
    ]);

    // price_list_assignments.scope_id is polymorphic and carries no key (D-24).
    foreach (DB::connection(pricingSchemaConnection())->select('pragma foreign_key_list("price_list_assignments")') as $key) {
        expect(((array) $key)['from'])->not->toBe('scope_id');
    }
});

it('cascades both documented deletes: tax_group_taxes on its group and price_list_items on its list', function () {
    pricingApplyTenantTree();
    $connection = DB::connection(pricingSchemaConnection());
    $now = pricingSchemaNow();
    $seed = pricingSchemaSeed();

    $group = pricingSchemaId('grpc1');
    $connection->table('tax_groups')->insert(['id' => $group, 'created_at' => $now, 'updated_at' => $now, 'code' => 'c1', 'name' => 'C1']);
    $connection->table('tax_group_taxes')->insert(['tax_group_id' => $group, 'tax_id' => $seed['tax'], 'sequence' => 1]);
    $connection->table('price_list_items')->insert([
        'id' => pricingSchemaId('itemc1'), 'price_list_id' => $seed['list'], 'product_id' => $seed['product'], 'price' => '1.0000',
    ]);

    $connection->table('tax_groups')->where('id', $group)->delete();
    $connection->table('price_lists')->where('id', $seed['list'])->delete();

    expect($connection->table('tax_group_taxes')->where('tax_group_id', $group)->exists())->toBeFalse()
        ->and($connection->table('price_list_items')->where('price_list_id', $seed['list'])->exists())->toBeFalse();
});

it('runs cleanly twice as a no-op, and no T-029 file declares a down()', function () {
    pricingApplyTenantTree();

    $before = Schema::connection(pricingSchemaConnection())->getTableListing();
    $groupsBefore = DB::connection(pricingSchemaConnection())->table('tax_groups')->count();

    expect(Artisan::call('migrate', [
        '--database' => pricingSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    expect(Schema::connection(pricingSchemaConnection())->getTableListing())->toBe($before)
        ->and(DB::connection(pricingSchemaConnection())->table('tax_groups')->count())->toBe($groupsBefore);

    $files = glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_10_0029*.php');

    expect($files)->toHaveCount(12);

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('function down(');
    }
});

it('reports no SourceRules violation across the twelve T-029 migrations', function () {
    $violations = [];

    foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_10_0029*.php') as $file) {
        $violations = array_merge($violations, SourceRules::inspect((string) file_get_contents($file), MigrationTree::Tenant));
    }

    expect($violations)->toBe([]);
});
