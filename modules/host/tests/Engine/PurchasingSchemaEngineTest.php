<?php

use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Features\ApplyReceivedQuantity\ApplyReceivedQuantity;
use Flexipos\Purchasing\Features\ApplyReceivedQuantity\ApplyReceivedQuantityHandler;
use Flexipos\Purchasing\Features\DeleteSupplier\DeleteSupplier;
use Flexipos\Purchasing\Features\DeleteSupplier\DeleteSupplierHandler;
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
| T-034 step 22 - the purchasing tables and their locks, against a real
| MySQL/MariaDB connection. NOT VERIFIED while ENGINE_HOLD is set.
|--------------------------------------------------------------------------
|
| Only the first case below runs under the hold. Everything after it is written
| for gate G-1 and has NEVER BEEN RUN against an engine, exactly as
| FinanceSchemaEngineTest and SalesSchemaEngineTest were: nothing in this file is
| claimed to pass, and no result of it appears in any report. Nothing here may
| reach a real MySQL or MariaDB server before the hold is lifted, and the local
| engine hosts databases belonging to other projects: the one database this file
| touches is created and dropped by its **exact name** through EngineDatabase,
| never by a sweep and never by LIKE.
|
| What each case is, and is not.
|
| - The schema cases (the declared decimals, the code and number indexes on the
|   real collation, the JSON validity of the two supplier documents, migrating
|   twice) are the `[ENGINE]` halves of criteria the SQLite stand-in cannot prove:
|   it compares text on its own collation and enforces JSON validity differently.
| - The lock cases are **single-process and non-blocking**, the shape
|   SalesSchemaEngineTest and FinanceSchemaEngineTest use: one session holds a
|   lock and the other session, with `innodb_lock_wait_timeout = 1`, times out on
|   it, which is a lock wait and is told apart from a deadlock by its error text.
|   The first session then commits and the second proceeds. The second session
|   calls the **real** handler, through the tenant connection pointed at it, so
|   what is shown is the handler's own lock order and not a re-statement of it.
| - Rows are built with explicit ids, never `insertGetId`, and with the
|   foreign-key checks off on both sessions so a row can be written without its
|   whole parent graph (the locks and keys under test are not the foreign keys).
| - **Not built: the simultaneous forms** (AC-78 with two applies truly at once,
|   the first-use number-sequence gap-lock deadlock, the `transaction(..., 2)`
|   retry on 1213). A deadlock needs two statements blocked on each other at the
|   same moment, which one process cannot produce: the second statement would
|   hang until the timeout, and a timeout is not a deadlock. They need a child
|   process holding a session, and writing that blind, for a case that cannot
|   run, would ship a test most likely wrong at G-1. The retry's shape is the one
|   the stand-in tests prove with an injected deadlock; its engine evidence is
|   still owed (plan risk 3).
*/

function purchasingEngineTenantName(): string
{
    return 'flexipos_t8test_t034_tenant';
}

/**
 * Migrate the tenant tree into the fixture database and return its connection name.
 */
function purchasingEngineMigrate(): string
{
    $name = EngineConnection::connectionName(purchasingEngineTenantName());
    EngineConnection::connection(purchasingEngineTenantName());

    expect(Artisan::call('migrate', [
        '--database' => $name,
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    return $name;
}

function purchasingEngineId(): string
{
    return Str::upper(Str::ulid());
}

/**
 * One migrated database seen through two connections, foreign-key checks off on both, and the permission resolver bound
 * to one that allows everything (the resolver is not what this file is about).
 *
 * @return array{first: Connection, second: Connection, firstName: string, secondName: string}
 */
function purchasingEngineTwoSessions(): array
{
    $firstName = purchasingEngineMigrate();

    config(['database.connections.t034_second' => config('database.connections.'.$firstName)]);

    $first = DB::connection($firstName);
    $second = DB::connection('t034_second');

    foreach ([$first, $second] as $connection) {
        $connection->statement('set session foreign_key_checks = 0');
    }

    $second->statement('set session innodb_lock_wait_timeout = 1');

    app()->instance(PermissionResolver::class, new class implements PermissionResolver
    {
        public function decide(string $employeeId, string $permissionKey, ?string $branchId): AccessDecision
        {
            return AccessDecision::allow();
        }
    });

    return ['first' => $first, 'second' => $second, 'firstName' => $firstName, 'secondName' => 't034_second'];
}

/**
 * Run a call with the tenant connection pointed at one session.
 *
 * @template T
 *
 * @param  callable(): T  $work
 * @return T
 */
function purchasingEngineAs(string $connectionName, callable $work): mixed
{
    $before = config('tenancy.connection');

    config(['tenancy.connection' => $connectionName]);

    try {
        return $work();
    } finally {
        config(['tenancy.connection' => $before]);
    }
}

/**
 * The refusal a callable ends in, or null when it does not refuse.
 */
function purchasingEngineRefusal(callable $work): ?PurchasingRefusal
{
    try {
        $work();
    } catch (PurchasingRefused $refused) {
        return $refused->reason;
    }

    return null;
}

/**
 * Assert that a call is blocked on a lock the other session holds: a lock wait timeout, not a deadlock.
 */
function purchasingEngineExpectLockWait(callable $work): void
{
    $caught = null;

    try {
        $work();
    } catch (QueryException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(QueryException::class)
        ->and($caught->getMessage())->toContain('Lock wait timeout');

    expect($caught->getMessage())->not->toContain('Deadlock');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function purchasingEngineSupplierRow(string $code, array $overrides = []): array
{
    return array_merge([
        'id' => purchasingEngineId(), 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null, 'created_by' => null, 'updated_by' => null,
        'code' => $code, 'name' => 'Supplier '.$code, 'legal_name' => null, 'contact_name' => null, 'phone' => null, 'email' => null,
        'address' => null, 'tax_no' => null, 'currency' => null, 'payment_terms_days' => 0, 'credit_limit' => '0.0000', 'bank_details' => null,
        'is_active' => true, 'notes' => null,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function purchasingEngineOrderRow(string $number, string $supplier, array $overrides = []): array
{
    return array_merge([
        'id' => purchasingEngineId(), 'created_at' => now(), 'updated_at' => now(), 'created_by' => null, 'updated_by' => null,
        'number' => $number, 'supplier_id' => $supplier, 'supplier_name' => 'Snapshot', 'supplier_tax_no' => null, 'payment_terms_days' => 0,
        'branch_id' => purchasingEngineId(), 'location_id' => purchasingEngineId(), 'request_id' => null, 'order_date' => '2026-11-16',
        'expected_date' => null, 'status' => 'approved', 'workflow_instance_id' => null, 'currency' => 'LKR', 'exchange_rate' => '1.00000000',
        'subtotal' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'shipping' => '0.0000', 'total' => '0.0000',
        'received_value' => '0.0000', 'terms' => null, 'notes' => null,
    ], $overrides);
}

/**
 * A line of 10.0000 base, nothing received.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function purchasingEngineLineRow(string $order, int $lineNo, array $overrides = []): array
{
    return array_merge([
        'id' => purchasingEngineId(), 'order_id' => $order, 'line_no' => $lineNo, 'product_id' => purchasingEngineId(), 'description' => 'Line '.$lineNo,
        'unit_id' => purchasingEngineId(), 'quantity' => '10.0000', 'unit_factor' => '1.000000', 'base_quantity' => '10.0000', 'unit_cost' => '1.0000',
        'subtotal' => '10.0000', 'discount_percent' => '0.0000', 'discount_amount' => '0.0000', 'tax_group_id' => purchasingEngineId(),
        'tax_amount' => '0.0000', 'line_total' => '10.0000', 'received_quantity' => '0.0000', 'received_base_quantity' => '0.0000', 'expected_date' => null,
    ], $overrides);
}

/**
 * `table => column => [precision, scale]` for every decimal the six create-table files declare, read from the files
 * themselves so the expectation cannot drift from them.
 *
 * @return array<string, array<string, array{0: int, 1: int}>>
 */
function purchasingEngineDeclaredDecimals(): array
{
    $declared = [];

    foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_16_00340[1-6]_*.php') ?: [] as $file) {
        $code = SourceRules::codeOnly((string) file_get_contents($file));

        if (preg_match("/Schema::create\(\s*'(\w+)'/", $code, $table) !== 1) {
            continue;
        }

        preg_match_all('/->\s*(?:decimal|unsignedDecimal)\s*\(\s*[\'"](\w+)[\'"]\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/', $code, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $declared[$table[1]][$match[1]] = [(int) $match[2], (int) $match[3]];
        }
    }

    return $declared;
}

it('refuses to reach the engine at all while the hold is set', function () {
    expect(fn () => EngineConnection::connection(purchasingEngineTenantName()))->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('AC-88: the six purchasing tables are applied with their declared decimals, and migrating twice is a no-op', function () {
    $db = EngineDatabase::engine();
    $tenantName = purchasingEngineTenantName();

    $db->recreate($tenantName);

    try {
        $connectionName = purchasingEngineMigrate();
        $declared = purchasingEngineDeclaredDecimals();

        // The request header holds no decimal; the other five tables do.
        expect(array_keys($declared))->toEqualCanonicalizing(['suppliers', 'purchase_request_items', 'purchase_orders', 'purchase_order_items', 'purchase_order_receipt_applications']);

        foreach ($declared as $table => $columns) {
            /** @var array<int, object{column_name: string, column_type: string}> $rows */
            $rows = DB::connection($connectionName)->select(
                'select column_name, column_type from information_schema.columns where table_schema = ? and table_name = ? and data_type = ?',
                [$tenantName, $table, 'decimal']
            );

            $seen = [];

            foreach ($rows as $row) {
                $seen[strtolower($row->column_name)] = strtolower($row->column_type);
            }

            foreach ($columns as $column => [$precision, $scale]) {
                expect($seen[$column] ?? null)->toBe("decimal({$precision},{$scale})", "Expected [{$table}.{$column}] to be decimal({$precision},{$scale}).");
            }
        }

        $before = DB::connection($connectionName)->select('select table_name from information_schema.tables where table_schema = ?', [$tenantName]);

        expect(Artisan::call('migrate', ['--database' => $connectionName, '--path' => (string) config('schema.tenant_migration_path'), '--force' => true]))->toBe(0)
            ->and(DB::connection($connectionName)->select('select table_name from information_schema.tables where table_schema = ?', [$tenantName]))->toEqual($before);
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('AC-1, AC-19, AC-40: the code and number indexes refuse ab1 against AB1 on the real collation, a soft-deleted supplier keeps its code, and malformed JSON is refused', function () {
    $db = EngineDatabase::engine();
    $tenantName = purchasingEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = purchasingEngineTwoSessions();
        $connection = $sessions['first'];

        $first = purchasingEngineSupplierRow('AB1');
        $connection->table('suppliers')->insert($first);

        // The application folds to upper case, so a lower-case code reaches the index only through a raw insert; the real
        // collation compares it equal to the stored form and the unique index refuses it.
        expect(fn () => $connection->table('suppliers')->insert(purchasingEngineSupplierRow('ab1')))->toThrow(UniqueConstraintViolationException::class);

        // A soft-deleted supplier keeps its code reserved.
        $connection->table('suppliers')->where('code', 'AB1')->update(['deleted_at' => now()]);

        expect(fn () => $connection->table('suppliers')->insert(purchasingEngineSupplierRow('AB1')))->toThrow(UniqueConstraintViolationException::class);

        // A document number is unique across the table; the same text on a second order is refused.
        $supplier = (string) $first['id'];
        $connection->table('purchase_orders')->insert(purchasingEngineOrderRow('PO000001', $supplier));

        expect(fn () => $connection->table('purchase_orders')->insert(purchasingEngineOrderRow('po000001', $supplier)))->toThrow(UniqueConstraintViolationException::class);

        // The two JSON documents are validated by the engine (a native JSON type on MySQL, a CHECK on MariaDB).
        foreach (['address', 'bank_details'] as $column) {
            expect(fn () => $connection->table('suppliers')->insert(purchasingEngineSupplierRow('J-'.$column, [$column => '{not json'])))->toThrow(QueryException::class);
        }

        $connection->table('suppliers')->insert(purchasingEngineSupplierRow('J-OK', ['address' => '{"city":"Colombo"}', 'bank_details' => '{"account":"1"}']));

        // A line's number within its order is unique.
        $order = (string) $connection->table('purchase_orders')->value('id');
        $connection->table('purchase_order_items')->insert(purchasingEngineLineRow($order, 1));

        expect(fn () => $connection->table('purchase_order_items')->insert(purchasingEngineLineRow($order, 1)))->toThrow(UniqueConstraintViolationException::class);
    } finally {
        DB::purge('t034_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('AC-78: an apply for one line waits while another session holds the order header, then both lines land and the status follows the stored sums', function () {
    $db = EngineDatabase::engine();
    $tenantName = purchasingEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = purchasingEngineTwoSessions();
        ['first' => $first] = $sessions;

        $supplier = purchasingEngineId();
        $order = purchasingEngineOrderRow('PO000001', $supplier);
        $orderId = (string) $order['id'];
        $lineOne = purchasingEngineLineRow($orderId, 1);
        $lineTwo = purchasingEngineLineRow($orderId, 2);

        $first->table('purchase_orders')->insert($order);
        $first->table('purchase_order_items')->insert($lineOne);
        $first->table('purchase_order_items')->insert($lineTwo);

        $apply = static fn (string $session, string $line): mixed => purchasingEngineAs($session, static fn () => app(ApplyReceivedQuantityHandler::class)->handle(
            new ApplyReceivedQuantity(['application_id' => purchasingEngineId(), 'line_id' => $line, 'received_base_delta' => '10.0000', 'received_quantity_delta' => '10.0000'], null)
        ));

        // A holds the order header FOR UPDATE, which is the first lock every apply takes (D-22).
        $first->beginTransaction();

        try {
            $first->table('purchase_orders')->where('id', $orderId)->lockForUpdate()->first();

            purchasingEngineExpectLockWait(static fn () => $apply($sessions['secondName'], (string) $lineTwo['id']));

            $first->commit();
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        // B's apply goes through once A is gone: one line is complete, the other is not, so the order is partly received.
        $apply($sessions['secondName'], (string) $lineTwo['id']);

        expect($first->table('purchase_orders')->where('id', $orderId)->value('status'))->toBe('partially_received');

        // A's own apply for the other line completes the order, and neither update was lost.
        $apply($sessions['firstName'], (string) $lineOne['id']);

        expect($first->table('purchase_orders')->where('id', $orderId)->value('status'))->toBe('received')
            ->and((string) $first->table('purchase_order_items')->where('id', $lineOne['id'])->value('received_base_quantity'))->toBe('10.0000')
            ->and((string) $first->table('purchase_order_items')->where('id', $lineTwo['id'])->value('received_base_quantity'))->toBe('10.0000')
            ->and($first->table('purchase_order_receipt_applications')->where('order_id', $orderId)->count())->toBe(2);
    } finally {
        DB::purge('t034_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('plan risk 9: a supplier delete waits on the shared lock a document write holds, then is refused supplier_in_use once that write commits', function () {
    $db = EngineDatabase::engine();
    $tenantName = purchasingEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = purchasingEngineTwoSessions();
        ['first' => $first] = $sessions;

        $supplier = purchasingEngineSupplierRow('LOCKED-1');
        $supplierId = (string) $supplier['id'];
        $first->table('suppliers')->insert($supplier);

        $delete = static fn () => purchasingEngineAs($sessions['secondName'], static fn () => app(DeleteSupplierHandler::class)->handle(new DeleteSupplier($supplierId, purchasingEngineId())));

        // A takes the supplier FOR SHARE, as a request line or an order does, writes the order that uses it, and holds.
        $first->beginTransaction();

        try {
            $first->table('suppliers')->where('id', $supplierId)->sharedLock()->first();
            $first->table('purchase_orders')->insert(purchasingEngineOrderRow('PO000001', $supplierId));

            purchasingEngineExpectLockWait($delete);

            $first->commit();
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        expect(purchasingEngineRefusal($delete))->toBe(PurchasingRefusal::SupplierInUse)
            ->and($first->table('suppliers')->where('id', $supplierId)->whereNull('deleted_at')->count())->toBe(1);
    } finally {
        DB::purge('t034_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
