<?php

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Features\CreateProduct\CreateProduct;
use Flexipos\Catalog\Features\CreateProduct\CreateProductHandler;
use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Contracts\Pricing\LineQuote;
use Flexipos\Contracts\Pricing\PricingEngine;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Features\CloseTaxRate\CloseTaxRate;
use Flexipos\Pricing\Features\CloseTaxRate\CloseTaxRateHandler;
use Flexipos\Pricing\Features\UpdateTaxGroup\UpdateTaxGroup;
use Flexipos\Pricing\Features\UpdateTaxGroup\UpdateTaxGroupHandler;
use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineDatabase;
use Flexipos\Testing\Engine\EngineOnHold;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| T-029 step 18 - the pricing tables and the adapter, against a real
| MySQL/MariaDB connection. NOT VERIFIED while ENGINE_HOLD is set.
|--------------------------------------------------------------------------
|
| Only the first case below runs under the hold. Everything after it is
| written for gate G-1 and has NEVER BEEN RUN against an engine: nothing in
| this file is claimed to pass, and no result of it appears in any report.
| That includes the D-29 collation case, the MySQL half of the proof that the
| database adapter never orders (AC-30.4): the SQLite stand-in's default
| collation is already bytewise, so it cannot show the hazard, and only this
| file can.
|
| The database is created and dropped by its exact name through
| EngineDatabase, never a sweep and never LIKE.
*/

function pricingEngineTenantName(): string
{
    return 'flexipos_t8test_t029_tenant';
}

/**
 * Migrate the whole tenant tree into the fixture database and return the name
 * of its connection.
 */
function pricingEngineMigrate(?string $path = null): string
{
    $tenantConnectionName = EngineConnection::connectionName(pricingEngineTenantName());
    EngineConnection::connection(pricingEngineTenantName());

    expect(Artisan::call('migrate', [
        '--database' => $tenantConnectionName,
        '--path' => $path ?? (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    return $tenantConnectionName;
}

function pricingEngineId(): string
{
    return Str::upper(Str::ulid());
}

/**
 * @return array<string, mixed>
 */
function pricingEngineProductRow(string $unit, string $category, ?string $group): array
{
    $row = [
        'id' => pricingEngineId(), 'created_at' => now(), 'updated_at' => now(), 'type' => 'simple',
        'sku' => 'P'.Str::random(8), 'name' => 'Widget', 'category_id' => $category, 'stock_unit_id' => $unit,
        'cost_price' => 0, 'selling_price' => '1.0000', 'track_stock' => 1, 'tracking_mode' => 'quantity',
        'is_weighable' => 0, 'price_mode' => 'per_unit', 'is_sellable' => 1, 'is_purchasable' => 1, 'is_active' => 1,
        'allow_price_override' => 0, 'allow_discount' => 1, 'sort_order' => 0, 'version' => 1,
    ];

    if ($group !== null) {
        $row['tax_group_id'] = $group;
    }

    return $row;
}

/**
 * A unit, a category and a product on the engine connection.
 *
 * @return array{unit: string, category: string, product: string, group: string}
 */
function pricingEngineProduct(Connection $connection): array
{
    $unit = pricingEngineId();
    $category = pricingEngineId();
    $group = (string) $connection->table('tax_groups')->value('id');

    $connection->table('units')->insert([
        'id' => $unit, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count',
        'precision' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $connection->table('categories')->insert([
        'id' => $category, 'code' => 'cat', 'name' => 'Cat', 'path' => $category, 'depth' => 0,
        'sort_order' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = pricingEngineProductRow($unit, $category, $group);
    $connection->table('products')->insert($row);

    return ['unit' => $unit, 'category' => $category, 'product' => (string) $row['id'], 'group' => $group];
}

function pricingEnginePriceList(Connection $connection, string $code, string $type = 'standard'): string
{
    $id = pricingEngineId();

    $connection->table('price_lists')->insert([
        'id' => $id, 'created_at' => now(), 'updated_at' => now(), 'code' => $code, 'name' => 'List '.$code,
        'type' => $type, 'currency' => 'USD', 'priority' => 0, 'is_active' => 1,
    ]);

    return $id;
}

it('refuses to reach the engine at all while the hold is set', function () {
    expect(fn () => EngineConnection::connection(pricingEngineTenantName()))->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('applies the nine pricing tables with the decimal shapes section 4 documents, and migrating twice is a no-op', function () {
    $db = EngineDatabase::engine();
    $tenantName = pricingEngineTenantName();

    $db->recreate($tenantName);

    try {
        $connectionName = pricingEngineMigrate();

        /** @var array<int, object{table_name: string, column_name: string, column_type: string}> $columns */
        $columns = DB::connection($connectionName)->select(
            'select table_name, column_name, column_type from information_schema.columns
             where table_schema = ? and (
                (table_name = ? and column_name in (?, ?, ?)) or (table_name = ? and column_name = ?)
                or (table_name = ? and column_name in (?, ?, ?, ?)))',
            [
                $tenantName,
                'price_list_items', 'price', 'min_qty', 'discount_percent',
                'tax_rates', 'rate',
                'discounts', 'value', 'min_amount', 'max_discount', 'approval_threshold',
            ]
        );

        $seen = [];

        foreach ($columns as $column) {
            $seen[$column->table_name.'.'.$column->column_name] = strtolower($column->column_type);
        }

        foreach ([
            'price_list_items.price' => 'decimal(18,4)', 'price_list_items.min_qty' => 'decimal(18,4)',
            'price_list_items.discount_percent' => 'decimal(9,4)', 'tax_rates.rate' => 'decimal(9,4)',
            'discounts.value' => 'decimal(18,4)', 'discounts.min_amount' => 'decimal(18,4)',
            'discounts.max_discount' => 'decimal(18,4)', 'discounts.approval_threshold' => 'decimal(18,4)',
        ] as $key => $type) {
            expect($seen[$key] ?? null)->toBe($type, "Expected [{$key}] to be [{$type}].");
        }

        $before = DB::connection($connectionName)->select('select table_name from information_schema.tables where table_schema = ?', [$tenantName]);

        expect(Artisan::call('migrate', ['--database' => $connectionName, '--path' => (string) config('schema.tenant_migration_path'), '--force' => true]))->toBe(0)
            ->and(DB::connection($connectionName)->select('select table_name from information_schema.tables where table_schema = ?', [$tenantName]))->toEqual($before);
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('the generated unit_key and default_marker keys refuse a second row, and applies_to must be valid JSON', function () {
    $db = EngineDatabase::engine();
    $tenantName = pricingEngineTenantName();

    $db->recreate($tenantName);

    try {
        $connection = DB::connection(pricingEngineMigrate());
        $seed = pricingEngineProduct($connection);
        $list = pricingEnginePriceList($connection, 'RETAIL');

        $item = static fn (?string $unit): array => [
            'id' => pricingEngineId(), 'price_list_id' => $list, 'product_id' => $seed['product'],
            'unit_id' => $unit, 'min_qty' => '1.0000', 'price' => '5.0000',
        ];

        $connection->table('price_list_items')->insert($item(null));

        // Two NULL-unit rows at the same min_qty: unit_key folds both to the sentinel.
        expect(fn () => $connection->table('price_list_items')->insert($item(null)))->toThrow(UniqueConstraintViolationException::class);

        // Control: a unit row alongside the NULL row is accepted.
        $connection->table('price_list_items')->insert($item($seed['unit']));

        expect($connection->table('price_list_items')->count())->toBe(2);

        // default_marker: the backfill already seeded one default group.
        $group = static fn (string $code): array => [
            'id' => pricingEngineId(), 'created_at' => now(), 'updated_at' => now(), 'code' => $code,
            'name' => $code, 'is_inclusive' => 0, 'is_default' => 1, 'is_active' => 1,
        ];

        expect(fn () => $connection->table('tax_groups')->insert($group('SECOND')))->toThrow(UniqueConstraintViolationException::class);

        // applies_to carries a JSON validity check.
        $discount = static fn (string $code, string $appliesTo): array => [
            'id' => pricingEngineId(), 'created_at' => now(), 'updated_at' => now(), 'code' => $code, 'name' => $code,
            'type' => 'percentage', 'value' => '10.0000', 'scope' => 'item', 'applies_to' => $appliesTo,
            'requires_approval' => 0, 'used_count' => 0, 'is_active' => 1,
        ];

        expect(fn () => $connection->table('discounts')->insert($discount('BAD', 'not json')))->toThrow(QueryException::class);

        $connection->table('discounts')->insert($discount('GOOD', '{"products": []}'));

        expect($connection->table('discounts')->where('code', 'GOOD')->exists())->toBeTrue();
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('the contract file adds both foreign keys on a populated products table, and every product ends with a tax group', function () {
    $db = EngineDatabase::engine();
    $tenantName = pricingEngineTenantName();
    $partial = 'storage/framework/testing/t029-engine-'.bin2hex(random_bytes(4));

    $db->recreate($tenantName);

    try {
        // The tree as it stood before the products trio (files 06 to 12), so a
        // product can exist before the columns do.
        mkdir(base_path($partial), 0777, true);

        foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/*.php') ?: [] as $file) {
            if (basename($file) < '2026_11_10_002906') {
                copy($file, base_path($partial).'/'.basename($file));
            }
        }

        $connectionName = pricingEngineMigrate($partial);
        $connection = DB::connection($connectionName);

        $unit = pricingEngineId();
        $category = pricingEngineId();

        $connection->table('units')->insert(['id' => $unit, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count', 'precision' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $connection->table('categories')->insert(['id' => $category, 'code' => 'cat', 'name' => 'Cat', 'path' => $category, 'depth' => 0, 'sort_order' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $row = pricingEngineProductRow($unit, $category, null);
        $connection->table('products')->insert($row);

        pricingEngineMigrate();

        expect($connection->table('products')->whereNull('tax_group_id')->count())->toBe(0)
            ->and($connection->table('products')->whereNotNull('purchase_tax_group_id')->count())->toBe(0);

        $keys = $connection->select(
            "select column_name from information_schema.key_column_usage
             where table_schema = ? and table_name = 'products' and referenced_table_name = 'tax_groups'",
            [$tenantName]
        );

        expect(array_map(static fn (object $key): string => $key->column_name, $keys))->toEqualCanonicalizing(['tax_group_id', 'purchase_tax_group_id']);
    } finally {
        $db->drop($tenantName);

        foreach (glob(base_path($partial).'/*.php') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir(base_path($partial))) {
            rmdir(base_path($partial));
        }
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('the rate-overlap check holds the parent tax row: a second session waits and times out', function () {
    $db = EngineDatabase::engine();
    $tenantName = pricingEngineTenantName();

    $db->recreate($tenantName);

    try {
        $first = DB::connection(pricingEngineMigrate());

        $authority = pricingEngineId();
        $tax = pricingEngineId();

        $first->table('tax_authorities')->insert(['id' => $authority, 'created_at' => now(), 'updated_at' => now(), 'code' => 'TA', 'name' => 'Authority']);
        $first->table('taxes')->insert([
            'id' => $tax, 'created_at' => now(), 'updated_at' => now(), 'authority_id' => $authority, 'code' => 'VAT', 'name' => 'VAT',
            'type' => 'percentage', 'is_compound' => 0, 'applies_to' => 'both', 'is_recoverable' => 0, 'is_withholding' => 0, 'is_active' => 1,
        ]);

        // A second session onto the same database, under another connection name.
        config(['database.connections.t029_second' => config('database.connections.'.EngineConnection::connectionName($tenantName))]);
        $second = DB::connection('t029_second');
        $second->statement('set session innodb_lock_wait_timeout = 1');

        $first->beginTransaction();

        try {
            $first->table('taxes')->where('id', $tax)->lockForUpdate()->first();

            $second->beginTransaction();

            expect(fn () => $second->table('taxes')->where('id', $tax)->lockForUpdate()->first())->toThrow(QueryException::class);

            $second->rollBack();
        } finally {
            $first->rollBack();
        }

        // Control: with the first session's lock gone, the second takes it at once.
        expect($second->table('taxes')->where('id', $tax)->lockForUpdate()->first())->not->toBeNull();
    } finally {
        DB::purge('t029_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('D-29: the real adapter orders four equal-priority promotions bytewise on this engine, which sorts them otherwise', function () {
    $db = EngineDatabase::engine();
    $tenantName = pricingEngineTenantName();

    $db->recreate($tenantName);

    $before = config('tenancy.connection');

    try {
        $connectionName = pricingEngineMigrate();
        $connection = DB::connection($connectionName);
        $seed = pricingEngineProduct($connection);

        // Inserted in the reverse of the bytewise order 10 < 9 < B < a.
        $lists = [];

        foreach (['a' => '70.0000', 'B' => '80.0000', '9' => '90.0000', '10' => '100.0000'] as $code => $price) {
            $list = pricingEnginePriceList($connection, (string) $code, 'promotion');

            $connection->table('price_list_items')->insert([
                'id' => pricingEngineId(), 'price_list_id' => $list, 'product_id' => $seed['product'],
                'unit_id' => null, 'min_qty' => '1.0000', 'price' => $price,
            ]);

            $lists[(string) $code] = $list;
        }

        // THE CONTROL. On this engine the column's own ORDER BY puts a before B,
        // so a SQL order would have crowned the wrong promotion. If this fails,
        // the hazard is absent on this collation and the next block proves less.
        $sorted = $connection->table('price_lists')->orderBy('code')->pluck('code')->map(static fn (mixed $code): string => (string) $code)->all();

        expect(array_search('a', $sorted, true))->toBeLessThan(array_search('B', $sorted, true));

        // THE CASE. The adapter, which never orders, reads this engine.
        config(['tenancy.connection' => $connectionName]);
        app()->forgetInstance(PricingEngine::class);

        $quote = new LineQuote(
            $seed['product'],
            new UnitSnapshot($seed['unit'], 'each', '1.0000', '1.000000', '1.0000', FactorSource::StockUnit),
            '2025-06-15T09:30:00.000Z',
            '2025-06-01',
            'USD',
        );

        foreach (['10' => '100.0000', '9' => '90.0000', 'B' => '80.0000', 'a' => '70.0000'] as $winner => $price) {
            $priced = app(PricingEngine::class)->quoteLine($quote);

            expect($priced->unitPrice)->toBe($price, "Expected the code [{$winner}] to win.")
                ->and($priced->source->priceListId)->toBe($lists[(string) $winner]);

            $connection->table('price_lists')->where('id', $lists[(string) $winner])->update(['is_active' => 0]);
        }
    } finally {
        config(['tenancy.connection' => $before]);
        app()->forgetInstance(PricingEngine::class);
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

// ---------------------------------------------------------------------------
// T-029 fix round, steps 27 and 28 (review W-1 and W-2, D-39): the lock-order
// fixes, seen from two sessions. NOT VERIFIED: written for gate G-1, never run.
//
// Each case is single-process and non-blocking: the first session always
// commits before the second proceeds. The second session fixes its snapshot
// with a plain read (what a handler's first plain statement does under
// REPEATABLE READ), the first session commits a change, and the handler then
// runs on the second session inside that already-open transaction by pointing
// `tenancy.connection` at it for the call. A locking read returns the latest
// committed row whatever the snapshot, which is exactly what the fixes rely on.
// ---------------------------------------------------------------------------

/**
 * One migrated tenant database seen through two connections.
 *
 * @return array{first: Connection, second: Connection, firstName: string}
 */
function pricingEngineTwoSessions(): array
{
    $firstName = pricingEngineMigrate();

    config(['database.connections.t029_second' => config('database.connections.'.$firstName)]);

    return ['first' => DB::connection($firstName), 'second' => DB::connection('t029_second'), 'firstName' => $firstName];
}

/**
 * Run a handler call with the tenant connection pointed at one session.
 *
 * @template T
 *
 * @param  callable(): T  $work
 * @return T
 */
function pricingEngineAs(string $connectionName, callable $work): mixed
{
    $before = config('tenancy.connection');

    config(['tenancy.connection' => $connectionName]);

    try {
        return $work();
    } finally {
        config(['tenancy.connection' => $before]);
    }
}

function pricingEngineTax(Connection $connection): string
{
    $authority = pricingEngineId();
    $tax = pricingEngineId();

    $connection->table('tax_authorities')->insert(['id' => $authority, 'created_at' => now(), 'updated_at' => now(), 'code' => 'TA', 'name' => 'Authority']);
    $connection->table('taxes')->insert([
        'id' => $tax, 'created_at' => now(), 'updated_at' => now(), 'authority_id' => $authority, 'code' => 'VAT', 'name' => 'VAT',
        'type' => 'percentage', 'is_compound' => 0, 'applies_to' => 'both', 'is_recoverable' => 0, 'is_withholding' => 0, 'is_active' => 1,
    ]);

    return $tax;
}

function pricingEngineRate(Connection $connection, string $tax, string $from, ?string $to): string
{
    $id = pricingEngineId();

    $connection->table('tax_rates')->insert([
        'id' => $id, 'created_at' => now(), 'updated_at' => now(), 'tax_id' => $tax, 'rate' => '15.0000',
        'effective_from' => $from, 'effective_to' => $to, 'note' => null,
    ]);

    return $id;
}

function pricingEngineRefusal(callable $work): PricingAdminRefused|CatalogRefused
{
    try {
        $work();
    } catch (PricingAdminRefused|CatalogRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The call was expected to be refused and succeeded.');
}

it('E-1 (W-1, AC-6a): a close on a stale snapshot still sees that the rate was closed meanwhile', function () {
    $db = EngineDatabase::engine();
    $tenantName = pricingEngineTenantName();

    $db->recreate($tenantName);

    try {
        ['first' => $first, 'second' => $second, 'firstName' => $firstName] = pricingEngineTwoSessions();

        $tax = pricingEngineTax($first);
        $rate = pricingEngineRate($first, $tax, '2024-01-01', null);

        $second->beginTransaction();

        try {
            // Fixes the second session's snapshot: the rate is open on it.
            $second->table('tax_rates')->count();

            pricingEngineAs($firstName, fn () => app(CloseTaxRateHandler::class)->handle(new CloseTaxRate($rate, ['effective_to' => '2025-01-01'])));

            // The control: the hazard is real on this engine. A plain read is
            // stale, a locking read is current.
            expect($second->table('tax_rates')->where('id', $rate)->value('effective_to'))->toBeNull()
                ->and($second->table('tax_rates')->where('id', $rate)->lockForUpdate()->value('effective_to'))->toBe('2025-01-01');

            $refused = pricingEngineRefusal(fn () => pricingEngineAs('t029_second', fn () => app(CloseTaxRateHandler::class)->handle(new CloseTaxRate($rate, ['effective_to' => '2026-01-01']))));

            expect($refused->reason->value)->toBe('validation');
        } finally {
            $second->rollBack();
        }

        expect($first->table('tax_rates')->where('id', $rate)->value('effective_to'))->toBe('2025-01-01');
    } finally {
        DB::purge('t029_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('E-2 (W-1, AC-6): the overlap check on a stale snapshot still sees a sibling written meanwhile', function () {
    $db = EngineDatabase::engine();
    $tenantName = pricingEngineTenantName();

    $db->recreate($tenantName);

    try {
        ['first' => $first, 'second' => $second] = pricingEngineTwoSessions();

        $tax = pricingEngineTax($first);
        $rate = pricingEngineRate($first, $tax, '2024-01-01', null);

        $second->beginTransaction();

        try {
            $second->table('tax_rates')->count();

            // Data written another way: a sibling that the second snapshot cannot see.
            pricingEngineRate($first, $tax, '2025-01-01', null);

            $refused = pricingEngineRefusal(fn () => pricingEngineAs('t029_second', fn () => app(CloseTaxRateHandler::class)->handle(new CloseTaxRate($rate, ['effective_to' => '2026-01-01']))));

            expect($refused->reason->value)->toBe('overlap');
        } finally {
            $second->rollBack();
        }
    } finally {
        DB::purge('t029_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('E-3 (W-1): closing a rate takes the parent tax row first, so a holder of that row blocks it', function () {
    $db = EngineDatabase::engine();
    $tenantName = pricingEngineTenantName();

    $db->recreate($tenantName);

    try {
        ['first' => $first, 'second' => $second] = pricingEngineTwoSessions();

        $tax = pricingEngineTax($first);
        $rate = pricingEngineRate($first, $tax, '2024-01-01', null);

        $second->statement('set session innodb_lock_wait_timeout = 1');

        $first->beginTransaction();

        try {
            $first->table('taxes')->where('id', $tax)->lockForUpdate()->first();

            expect(fn () => pricingEngineAs('t029_second', fn () => app(CloseTaxRateHandler::class)->handle(new CloseTaxRate($rate, ['effective_to' => '2025-01-01']))))->toThrow(QueryException::class);
        } finally {
            $first->rollBack();
        }

        // Control: with the first session's lock gone the same call succeeds.
        pricingEngineAs('t029_second', fn () => app(CloseTaxRateHandler::class)->handle(new CloseTaxRate($rate, ['effective_to' => '2025-01-01'])));

        expect($first->table('tax_rates')->where('id', $rate)->value('effective_to'))->toBe('2025-01-01');
    } finally {
        DB::purge('t029_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

/**
 * @return array<string, mixed>
 */
function pricingEngineProductFields(string $unit, string $category, ?string $group): array
{
    $fields = ['type' => 'simple', 'sku' => 'P'.Str::random(8), 'name' => 'Widget', 'category_id' => $category, 'stock_unit_id' => $unit];

    if ($group !== null) {
        $fields['tax_group_id'] = $group;
    }

    return $fields;
}

it('E-4 (W-2): a product created on a stale snapshot is still refused a tax group deactivated meanwhile', function () {
    $db = EngineDatabase::engine();
    $tenantName = pricingEngineTenantName();

    $db->recreate($tenantName);

    try {
        ['first' => $first, 'second' => $second, 'firstName' => $firstName] = pricingEngineTwoSessions();

        $seed = pricingEngineProduct($first);
        $group = pricingEngineId();

        $first->table('tax_groups')->insert([
            'id' => $group, 'created_at' => now(), 'updated_at' => now(), 'code' => 'G', 'name' => 'G',
            'is_inclusive' => 0, 'is_default' => 0, 'is_active' => 1,
        ]);

        $second->beginTransaction();

        try {
            // Fixes the second session's snapshot: the group is active on it.
            $second->table('tax_groups')->count();

            pricingEngineAs($firstName, fn () => app(UpdateTaxGroupHandler::class)->handle(new UpdateTaxGroup($group, ['is_active' => false])));

            $fields = pricingEngineProductFields($seed['unit'], $seed['category'], $group);
            $refused = pricingEngineRefusal(fn () => pricingEngineAs('t029_second', fn () => app(CreateProductHandler::class)->handle(new CreateProduct($fields))));

            expect($refused->reason->value)->toBe('inactive_tax_group');
        } finally {
            $second->rollBack();
        }

        expect($first->table('products')->where('tax_group_id', $group)->count())->toBe(0);
    } finally {
        DB::purge('t029_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('E-5 (W-2): a product created on a stale snapshot is refused when the default group was retired meanwhile', function () {
    $db = EngineDatabase::engine();
    $tenantName = pricingEngineTenantName();

    $db->recreate($tenantName);

    try {
        ['first' => $first, 'second' => $second, 'firstName' => $firstName] = pricingEngineTwoSessions();

        $seed = pricingEngineProduct($first);
        $default = (string) $first->table('tax_groups')->where('is_default', 1)->value('id');

        $second->beginTransaction();

        try {
            $second->table('tax_groups')->count();

            // Retiring the default group clears its default flag.
            pricingEngineAs($firstName, fn () => app(UpdateTaxGroupHandler::class)->handle(new UpdateTaxGroup($default, ['is_active' => false])));

            $fields = pricingEngineProductFields($seed['unit'], $seed['category'], null);
            $refused = pricingEngineRefusal(fn () => pricingEngineAs('t029_second', fn () => app(CreateProductHandler::class)->handle(new CreateProduct($fields))));

            expect($refused->reason->value)->toBe('no_default_tax_group');
        } finally {
            $second->rollBack();
        }
    } finally {
        DB::purge('t029_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
