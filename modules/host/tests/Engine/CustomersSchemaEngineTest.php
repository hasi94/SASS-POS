<?php

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Customers\Ledger\CreditLedgerWriter;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineDatabase;
use Flexipos\Testing\Engine\EngineOnHold;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| T-028 step 17 - the eight customer tables, against a real MySQL/MariaDB
| connection. AC-20's and D-15's CHECK clause, and the lock-holding halves
| of AC-22 and AC-29, stay NOT VERIFIED while ENGINE_HOLD is set and gate
| G-1 is unmet (AC-39). The code to verify all of them is ready - the same
| shape CatalogSchemaEngineTest/WorkflowSchemaEngineTest already carry for
| their own engine-only clauses.
|
| The database here is created and dropped by exact name, through
| EngineDatabase, in the same run - never a sweep (plan step 17).
*/

function customersSchemaEngineTenantName(): string
{
    return 'flexipos_t8test_t028_tenant';
}

/**
 * Migrate the fixture tenant and return its connection name.
 */
function customersSchemaEngineMigrate(): string
{
    $tenantName = customersSchemaEngineTenantName();
    $tenantConnectionName = EngineConnection::connectionName($tenantName);
    EngineConnection::connection($tenantName);

    expect(Artisan::call('migrate', [
        '--database' => $tenantConnectionName,
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    return $tenantConnectionName;
}

/**
 * A second, independent session onto the same database - the shape a
 * concurrent writer actually has. `EngineConnection::connection()` caches
 * one Laravel `Connection` per database name, so a true second session
 * needs its own connection name under the same stanza; it is purged (and
 * so disconnected) at the end of every test that opens one.
 */
function customersSchemaEngineSecondConnection(string $tenantName): Connection
{
    $name = 'flexipos_t8test_t028_tenant_session_b';

    Config::set('database.connections.'.$name, EngineConnection::stanza($tenantName));
    DB::purge($name);

    return DB::connection($name);
}

/**
 * A minimal, insertable customer row, as a raw array - every column the
 * migration declares `NOT NULL` with no default.
 *
 * @return array<string, mixed>
 */
function customersSchemaEngineCustomerRow(string $code, ?string $currency = 'LKR'): array
{
    return [
        'id' => Str::upper(Str::ulid()),
        'created_at' => now(), 'updated_at' => now(),
        'code' => $code, 'name' => 'Engine customer '.$code,
        'currency' => $currency,
        'version' => 1,
    ];
}

/**
 * Insert a minimal customer row and return its own id (W-2): `id` is a
 * CHAR(26) ULID with no AUTO_INCREMENT, so `insertGetId()` would return `0`
 * on real MySQL - the row's own generated id is the only correct source.
 */
function customersSchemaEngineInsertCustomer(Connection $connection, string $code, ?string $currency = 'LKR'): string
{
    $row = customersSchemaEngineCustomerRow($code, $currency);
    $connection->table('customers')->insert($row);

    return (string) $row['id'];
}

/**
 * A `CreditLedgerWriter` built directly (not through the container), so a
 * second connection's call is not entangled with the app's own
 * `tenancy.connection`/`AuditTrail` bindings (W-2's "connection B calls the
 * writer"). The audit side is irrelevant to what this test proves, so its
 * `AuditTrail` is an inert double.
 */
function customersSchemaEngineWriter(): CreditLedgerWriter
{
    return new CreditLedgerWriter(new RunnerClock, new class implements AuditTrail
    {
        public function record(
            string $module,
            ?string $actorId,
            string $action,
            string $subjectType,
            string $subjectId,
            ?array $before,
            ?array $after,
            ?string $branchId = null,
        ): void {
            // Inert: this test proves locking and balance behaviour, not auditing.
        }
    });
}

it('refuses to reach the engine at all while the hold is set', function () {
    expect(fn () => EngineConnection::connection(customersSchemaEngineTenantName()))->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('applies the eight customer tables, with the decimal shapes section 4 documents', function () {
    $db = EngineDatabase::engine();
    $tenantName = customersSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = customersSchemaEngineMigrate();

        foreach ([
            'customer_groups', 'customers', 'customer_addresses', 'customer_ledger',
            'loyalty_programs', 'loyalty_transactions', 'customer_notes', 'customer_communications',
        ] as $table) {
            expect(Schema::connection($tenantConnectionName)->hasTable($table))->toBeTrue();
        }

        /** @var array<int, object{table_name: string, column_name: string, column_type: string}> $columns */
        $columns = DB::connection($tenantConnectionName)->select(
            'select table_name, column_name, column_type from information_schema.columns
             where table_schema = ? and table_name in (?, ?, ?, ?)
               and column_name in (?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantName,
                'customers', 'customer_groups', 'customer_ledger', 'loyalty_transactions',
                'credit_limit', 'discount_percent', 'debit', 'credit', 'balance_after', 'points', 'value',
            ]
        );

        $expected = [
            'customers.credit_limit' => 'decimal(18,4)',
            'customer_groups.discount_percent' => 'decimal(9,4)',
            'customer_ledger.debit' => 'decimal(18,4)',
            'customer_ledger.credit' => 'decimal(18,4)',
            'customer_ledger.balance_after' => 'decimal(18,4)',
            'loyalty_transactions.points' => 'decimal(18,4)',
            'loyalty_transactions.value' => 'decimal(18,4)',
            'loyalty_transactions.balance_after' => 'decimal(18,4)',
        ];

        $seen = [];

        foreach ($columns as $column) {
            $seen[$column->table_name.'.'.$column->column_name] = strtolower($column->column_type);
        }

        foreach ($expected as $key => $type) {
            expect($seen[$key] ?? null)->toBe($type, "Expected [{$key}] to be [{$type}], got [".($seen[$key] ?? 'absent').'].');
        }

        // Migrating twice is a no-op.
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

it("AC-20's own [ENGINE] clause: customer_ledger's CHECK refuses both sides non-zero and a negative side", function () {
    $db = EngineDatabase::engine();
    $tenantName = customersSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = customersSchemaEngineMigrate();
        $connection = DB::connection($tenantConnectionName);

        $customerId = customersSchemaEngineInsertCustomer($connection, 'check-1');

        $base = [
            'id' => Str::upper(Str::ulid()), 'customer_id' => $customerId, 'type' => 'adjustment',
            'reference_type' => 'engine_test', 'currency' => 'LKR', 'balance_after' => 0,
            'created_at' => now(),
        ];

        // Both sides non-zero (D-15).
        expect(fn () => $connection->table('customer_ledger')->insert($base + [
            'reference_id' => Str::upper(Str::ulid()), 'debit' => 1, 'credit' => 1,
        ]))->toThrow(QueryException::class);

        // A negative side.
        expect(fn () => $connection->table('customer_ledger')->insert($base + [
            'reference_id' => Str::upper(Str::ulid()), 'debit' => -1, 'credit' => 0,
        ]))->toThrow(QueryException::class);

        // Control: exactly one non-negative side is accepted.
        $goodReference = Str::upper(Str::ulid());
        $connection->table('customer_ledger')->insert($base + [
            'reference_id' => $goodReference, 'debit' => 1, 'credit' => 0,
        ]);

        expect($connection->table('customer_ledger')->where('reference_id', $goodReference)->exists())->toBeTrue();
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('invalid JSON in loyalty_programs.tier_rules is refused, and valid JSON is accepted', function () {
    $db = EngineDatabase::engine();
    $tenantName = customersSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = customersSchemaEngineMigrate();
        $connection = DB::connection($tenantConnectionName);

        expect(fn () => $connection->table('loyalty_programs')->insert([
            'id' => Str::upper(Str::ulid()), 'created_at' => now(), 'updated_at' => now(),
            'name' => 'Bad JSON', 'tier_rules' => 'not valid json', 'is_active' => 1,
        ]))->toThrow(QueryException::class);

        $goodId = Str::upper(Str::ulid());
        $connection->table('loyalty_programs')->insert([
            'id' => $goodId, 'created_at' => now(), 'updated_at' => now(),
            'name' => 'Good JSON',
            'tier_rules' => json_encode([['tier' => 'bronze', 'min_points' => '0.0000']]),
            'is_active' => 1,
        ]);

        expect($connection->table('loyalty_programs')->where('id', $goodId)->exists())->toBeTrue();
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it("customer_ledger's idempotency key and customer_addresses' default-per-type key each refuse their second row", function () {
    $db = EngineDatabase::engine();
    $tenantName = customersSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = customersSchemaEngineMigrate();
        $connection = DB::connection($tenantConnectionName);

        $customerId = customersSchemaEngineInsertCustomer($connection, 'dup-1');

        // (reference_type, reference_id, type): the replay idempotency key.
        $referenceId = Str::upper(Str::ulid());

        $connection->table('customer_ledger')->insert([
            'id' => Str::upper(Str::ulid()), 'customer_id' => $customerId, 'type' => 'payment',
            'reference_type' => 'engine_test', 'reference_id' => $referenceId,
            'debit' => 0, 'credit' => 10, 'balance_after' => 10, 'currency' => 'LKR', 'created_at' => now(),
        ]);

        expect(fn () => $connection->table('customer_ledger')->insert([
            'id' => Str::upper(Str::ulid()), 'customer_id' => $customerId, 'type' => 'payment',
            'reference_type' => 'engine_test', 'reference_id' => $referenceId,
            'debit' => 0, 'credit' => 20, 'balance_after' => 30, 'currency' => 'LKR', 'created_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);

        // default_customer_id: a stored generated column, NULL on every
        // non-default row, the customer's id on the default one - a
        // second default of the same type collides on that generated
        // value, while a default of a *different* type for the same
        // customer is unconstrained.
        $connection->table('customer_addresses')->insert([
            'id' => Str::upper(Str::ulid()), 'created_at' => now(), 'updated_at' => now(),
            'customer_id' => $customerId, 'type' => 'billing', 'is_default' => 1,
        ]);

        expect(fn () => $connection->table('customer_addresses')->insert([
            'id' => Str::upper(Str::ulid()), 'created_at' => now(), 'updated_at' => now(),
            'customer_id' => $customerId, 'type' => 'billing', 'is_default' => 1,
        ]))->toThrow(UniqueConstraintViolationException::class);

        // Control: a default delivery address for the same customer is
        // accepted - a different `type` is a different generated-column
        // value.
        $connection->table('customer_addresses')->insert([
            'id' => Str::upper(Str::ulid()), 'created_at' => now(), 'updated_at' => now(),
            'customer_id' => $customerId, 'type' => 'delivery', 'is_default' => 1,
        ]);

        expect($connection->table('customer_addresses')->where('customer_id', $customerId)->where('type', 'delivery')->exists())->toBeTrue();
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it("a case-variant duplicate customers.code is refused by the unique index, under this connection's collation", function () {
    $db = EngineDatabase::engine();
    $tenantName = customersSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = customersSchemaEngineMigrate();
        $connection = DB::connection($tenantConnectionName);

        $connection->table('customers')->insert(customersSchemaEngineCustomerRow('CUST-001'));

        expect(fn () => $connection->table('customers')->insert(customersSchemaEngineCustomerRow('cust-001')))
            ->toThrow(UniqueConstraintViolationException::class);
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('AC-22, AC-29: a concurrent writer waits on the customer row lock, times out, then succeeds after the holder commits', function () {
    $db = EngineDatabase::engine();
    $tenantName = customersSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = customersSchemaEngineMigrate();
        $connectionA = DB::connection($tenantConnectionName);
        $connectionB = customersSchemaEngineSecondConnection($tenantName);

        try {
            $connectionB->statement('set session innodb_lock_wait_timeout = 1');

            $customerId = customersSchemaEngineInsertCustomer($connectionA, 'lock-1');
            $writerB = customersSchemaEngineWriter();
            $referenceId = Str::upper(Str::ulid());

            $connectionA->beginTransaction();
            $connectionA->table('customers')->where('id', $customerId)->lockForUpdate()->first();

            // Connection B calls the writer itself, under a one-second
            // wait. It must time out on the customer row's lock while A
            // still holds it, and write nothing - no ledger row, no
            // balance change (W-2).
            expect(fn () => $writerB->post(
                $connectionB, $customerId, CreditEntryType::Adjustment, 'engine_test', $referenceId,
                5, 0, 'LKR', null, null, null, null,
            ))->toThrow(QueryException::class);

            expect($connectionB->table('customer_ledger')->where('reference_id', $referenceId)->exists())->toBeFalse();
            expect($connectionB->table('customers')->where('id', $customerId)->value('credit_balance'))->toEqual(0);

            $connectionA->table('customers')->where('id', $customerId)->update(['credit_balance' => '5.0000']);
            $connectionA->commit();

            // After A commits, B's retry through the writer succeeds and
            // its own `balance_after` includes A's write: 5 (A) + 5 (B's
            // debit) = 10.
            $retried = $writerB->post(
                $connectionB, $customerId, CreditEntryType::Adjustment, 'engine_test', $referenceId,
                5, 0, 'LKR', null, null, null, null,
            );

            expect($retried->replayed)->toBeFalse();
            expect($retried->balanceAfter)->toEqual('10.0000');
            expect((string) $connectionB->table('customers')->where('id', $customerId)->value('credit_balance'))->toEqual('10.0000');
        } finally {
            DB::purge('flexipos_t8test_t028_tenant_session_b');
        }
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('W-1: a concurrent replay under REPEATABLE READ is reported as replayed, not a false replay_conflict, because the catch-block re-read is a locking read', function () {
    $db = EngineDatabase::engine();
    $tenantName = customersSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = customersSchemaEngineMigrate();
        $connectionA = DB::connection($tenantConnectionName);
        $connectionB = customersSchemaEngineSecondConnection($tenantName);

        try {
            $customerId = customersSchemaEngineInsertCustomer($connectionA, 'repeat-1');
            $writer = customersSchemaEngineWriter();
            $referenceId = Str::upper(Str::ulid());

            // B's transaction - and so its REPEATABLE READ snapshot - opens
            // before A's row exists at all.
            $connectionB->beginTransaction();
            $connectionB->table('customers')->where('id', $customerId)->first();

            // A posts and commits the entry B is about to collide with.
            $postingA = $writer->post(
                $connectionA, $customerId, CreditEntryType::Adjustment, 'engine_test', $referenceId,
                5, 0, 'LKR', null, null, null, null,
            );

            expect($postingA->replayed)->toBeFalse();

            // B, still inside the transaction opened before A's commit
            // (so its own replay precheck still misses under the stale
            // snapshot), posts the identical payload under the identical
            // key. Its INSERT then hits the unique key A's commit created;
            // the catch-block's locking re-read must see A's row despite
            // the snapshot, and report a replay rather than
            // `replay_conflict` (W-1).
            $postingB = $writer->post(
                $connectionB, $customerId, CreditEntryType::Adjustment, 'engine_test', $referenceId,
                5, 0, 'LKR', null, null, null, null,
            );

            $connectionB->commit();

            expect($postingB->replayed)->toBeTrue()
                ->and($postingB->entryId)->toBe($postingA->entryId)
                ->and($postingB->balanceAfter)->toBe($postingA->balanceAfter);
        } finally {
            DB::purge('flexipos_t8test_t028_tenant_session_b');
        }
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
