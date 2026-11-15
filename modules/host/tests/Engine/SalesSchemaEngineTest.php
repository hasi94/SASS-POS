<?php

use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Kernel\Schema\Support\SourceRules;
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
| T-030 step 24 - the sales tables and their locks, against a real
| MySQL/MariaDB connection. NOT VERIFIED while ENGINE_HOLD is set.
|--------------------------------------------------------------------------
|
| Only the first case below runs under the hold. Everything after it is written
| for gate G-1 and has NEVER BEEN RUN against an engine, exactly as
| PricingSchemaEngineTest was: nothing in this file is claimed to pass, and no
| result of it appears in any report.
|
| What each case is, and is not.
|
| - The schema cases (the decimal shapes, `open_terminal_key`, JSON validity, the
|   contract file on a populated `loyalty_transactions`, migrating twice) are the
|   `[ENGINE]` halves of criteria the SQLite stand-in cannot prove: it compiles no
|   generated-column uniqueness the way MySQL does, enforces no JSON check, and
|   reports a decimal without its length.
| - The lock cases are **single-process and non-blocking**, the shape
|   PricingSchemaEngineTest uses: the first session always commits before the
|   second proceeds. The second session fixes its snapshot with a plain read, the
|   first commits a change, and the second then runs a locking read inside its
|   already-open transaction, which returns the latest committed row whatever the
|   snapshot. That is the engine behaviour each handler's lock order relies on
|   (R-5), shown on the rows and on the real `DocumentNumbers`; **it is not the
|   handlers' own code**. A held lock is shown by the second session timing out
|   (`innodb_lock_wait_timeout = 1`), which is a lock wait and is told apart from
|   a deadlock by its error text.
| - **Not built: the sibling row that runs two `CompleteSale` calls on two
|   connections** (plan step 24, chunk B's NOT VERIFIED item for the
|   `transaction(..., 2)` retry). A handler call needs a branch, location,
|   terminal, employee, product, tax group, method, shift, sale, line and tender
|   on the engine connection, and every seed helper this package owns reads the
|   SQLite fixture connection, not the engine's. Building those rows blind, for a
|   case that cannot run, would have shipped a test most likely wrong at G-1. The
|   retry's shape is proven by `DeadlockingNumbers` on the stand-in; its engine
|   evidence is still owed.
|
| The database is created and dropped by its exact name through EngineDatabase,
| never a sweep and never LIKE.
*/

function salesEngineTenantName(): string
{
    return 'flexipos_t8test_t030_tenant';
}

/**
 * Migrate the tenant tree (or a given path of it) into the fixture database and return its connection name.
 */
function salesEngineMigrate(?string $path = null): string
{
    $name = EngineConnection::connectionName(salesEngineTenantName());
    EngineConnection::connection(salesEngineTenantName());

    expect(Artisan::call('migrate', [
        '--database' => $name,
        '--path' => $path ?? (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    return $name;
}

function salesEngineId(): string
{
    return Str::upper(Str::ulid());
}

/**
 * One migrated database seen through two connections, with the foreign-key checks off on both so a row can be
 * written without its whole parent graph (the locks and keys under test are not the foreign keys).
 *
 * @return array{first: Connection, second: Connection, firstName: string, secondName: string}
 */
function salesEngineTwoSessions(): array
{
    $firstName = salesEngineMigrate();

    config(['database.connections.t030_second' => config('database.connections.'.$firstName)]);

    $first = DB::connection($firstName);
    $second = DB::connection('t030_second');

    foreach ([$first, $second] as $connection) {
        $connection->statement('set session foreign_key_checks = 0');
    }

    $second->statement('set session innodb_lock_wait_timeout = 1');

    return ['first' => $first, 'second' => $second, 'firstName' => $firstName, 'secondName' => 't030_second'];
}

/**
 * Run a call with the tenant connection pointed at one session.
 *
 * @template T
 *
 * @param  callable(): T  $work
 * @return T
 */
function salesEngineAs(string $connectionName, callable $work): mixed
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
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function salesEngineShiftRow(string $terminal, string $status = 'open', array $overrides = []): array
{
    return array_merge([
        'id' => salesEngineId(), 'created_at' => now(), 'updated_at' => now(), 'terminal_id' => $terminal,
        'branch_id' => salesEngineId(), 'employee_id' => salesEngineId(), 'number' => 'SH'.Str::random(8),
        'status' => $status, 'opened_at' => now(), 'closed_at' => null, 'opening_cash' => '100.0000',
    ], $overrides);
}

/**
 * `table => column => [precision, scale]` for every decimal this task's eight create-table files declare, read from
 * the files themselves so the expectation cannot drift from them.
 *
 * @return array<string, array<string, array{0: int, 1: int}>>
 */
function salesEngineDeclaredDecimals(): array
{
    $declared = [];

    foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_10_00300[1-8]_*.php') ?: [] as $file) {
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
    expect(fn () => EngineConnection::connection(salesEngineTenantName()))->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('applies the eight sales tables with the decimal shapes the migrations declare, and migrating twice is a no-op', function () {
    $db = EngineDatabase::engine();
    $tenantName = salesEngineTenantName();

    $db->recreate($tenantName);

    try {
        $connectionName = salesEngineMigrate();
        $declared = salesEngineDeclaredDecimals();

        expect($declared)->toHaveKeys(['cash_shifts', 'cash_movements', 'sales', 'sale_items', 'sale_tenders']);

        /** @var array<int, object{table_name: string, column_name: string, column_type: string}> $columns */
        $columns = DB::connection($connectionName)->select(
            'select table_name, column_name, column_type from information_schema.columns where table_schema = ? and data_type = ?',
            [$tenantName, 'decimal']
        );

        $seen = [];

        foreach ($columns as $column) {
            $seen[strtolower($column->table_name)][strtolower($column->column_name)] = strtolower($column->column_type);
        }

        foreach ($declared as $table => $byColumn) {
            foreach ($byColumn as $column => [$precision, $scale]) {
                expect($seen[$table][$column] ?? null)->toBe("decimal({$precision},{$scale})", "Expected [{$table}.{$column}] to be decimal({$precision},{$scale}).");
            }
        }

        $before = DB::connection($connectionName)->select('select table_name from information_schema.tables where table_schema = ?', [$tenantName]);

        expect(Artisan::call('migrate', ['--database' => $connectionName, '--path' => (string) config('schema.tenant_migration_path'), '--force' => true]))->toBe(0)
            ->and(DB::connection($connectionName)->select('select table_name from information_schema.tables where table_schema = ?', [$tenantName]))->toEqual($before);
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('open_terminal_key refuses a second open shift on a terminal, accepts a closed one beside it, and totals_by_method must be valid JSON', function () {
    $db = EngineDatabase::engine();
    $tenantName = salesEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = salesEngineTwoSessions();
        $connection = $sessions['first'];
        $terminal = salesEngineId();

        $connection->table('cash_shifts')->insert(salesEngineShiftRow($terminal));

        // A second open shift on the same terminal: the stored key folds both to the terminal id.
        expect(fn () => $connection->table('cash_shifts')->insert(salesEngineShiftRow($terminal)))->toThrow(UniqueConstraintViolationException::class);

        // Control: a closed shift on the same terminal, and an open one on another terminal, are accepted.
        $connection->table('cash_shifts')->insert(salesEngineShiftRow($terminal, 'closed', ['closed_at' => now()]));
        $connection->table('cash_shifts')->insert(salesEngineShiftRow(salesEngineId()));

        expect($connection->table('cash_shifts')->count())->toBe(3);

        // totals_by_method carries a JSON validity check.
        expect(fn () => $connection->table('cash_shifts')->insert(salesEngineShiftRow(salesEngineId(), 'closed', ['closed_at' => now(), 'totals_by_method' => 'not json'])))->toThrow(QueryException::class);

        $connection->table('cash_shifts')->insert(salesEngineShiftRow(salesEngineId(), 'closed', ['closed_at' => now(), 'totals_by_method' => '{}']));

        expect($connection->table('cash_shifts')->count())->toBe(4);
    } finally {
        DB::purge('t030_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('contract file 10 adds the loyalty sale key on a populated loyalty_transactions, and refuses when a sale_id names no sale', function () {
    $db = EngineDatabase::engine();
    $tenantName = salesEngineTenantName();
    $partial = 'storage/framework/testing/t030-engine-'.bin2hex(random_bytes(4));

    $db->recreate($tenantName);

    try {
        // The tree as it stood before file 10, so a loyalty row can exist before the key does.
        mkdir(base_path($partial), 0777, true);

        foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/*.php') ?: [] as $file) {
            if (basename($file) < '2026_11_10_003010') {
                copy($file, base_path($partial).'/'.basename($file));
            }
        }

        $connectionName = salesEngineMigrate($partial);
        $connection = DB::connection($connectionName);
        $connection->statement('set session foreign_key_checks = 0');

        $row = static fn (?string $saleId): array => [
            'id' => salesEngineId(), 'customer_id' => salesEngineId(), 'program_id' => salesEngineId(), 'type' => 'earn',
            'points' => '1.0000', 'balance_after' => '1.0000', 'created_at' => now(), 'sale_id' => $saleId,
        ];

        // A row naming no sale: the file counts it and refuses, naming the table and column and changing nothing.
        $connection->table('loyalty_transactions')->insert($row(salesEngineId()));
        $connection->statement('set session foreign_key_checks = 1');

        expect(fn () => salesEngineMigrate())->toThrow(Throwable::class);

        // Corrected: the row's sale_id is cleared, the file runs, and the key is there.
        $connection->table('loyalty_transactions')->update(['sale_id' => null]);

        salesEngineMigrate();

        $keys = $connection->select(
            "select column_name from information_schema.key_column_usage
             where table_schema = ? and table_name = 'loyalty_transactions' and referenced_table_name = 'sales'",
            [$tenantName]
        );

        expect(array_map(static fn (object $key): string => $key->column_name, $keys))->toBe(['sale_id'])
            ->and($connection->table('loyalty_transactions')->count())->toBe(1);
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

it('a second session sees a completion committed after its snapshot, and waits on a row the first still holds (the sale lock, AC-17)', function () {
    $db = EngineDatabase::engine();
    $tenantName = salesEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = salesEngineTwoSessions();
        ['first' => $first, 'second' => $second] = $sessions;
        $shift = salesEngineId();
        $sale = salesEngineId();

        $first->table('sales')->insert([
            'id' => $sale, 'created_at' => now(), 'updated_at' => now(), 'terminal_id' => salesEngineId(), 'branch_id' => salesEngineId(),
            'location_id' => salesEngineId(), 'shift_id' => $shift, 'employee_id' => salesEngineId(), 'type' => 'sale', 'status' => 'draft',
            'business_date' => '2026-11-12', 'business_date_source' => 'server', 'currency' => 'LKR',
        ]);

        // The second session fixes its snapshot while the sale is a draft.
        $second->beginTransaction();

        try {
            expect($second->table('sales')->where('id', $sale)->value('status'))->toBe('draft');

            // The first completes and commits. A plain read in the second still says draft; a locking read says completed.
            $first->table('sales')->where('id', $sale)->update(['status' => 'completed']);

            expect($second->table('sales')->where('id', $sale)->value('status'))->toBe('draft')
                ->and($second->table('sales')->where('id', $sale)->lockForUpdate()->value('status'))->toBe('completed');

            $second->rollBack();
        } finally {
            if ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
        }

        // The wait: the first holds the row, the second times out on it, and the timeout is a lock wait and not a deadlock.
        $first->beginTransaction();

        try {
            $first->table('sales')->where('id', $sale)->lockForUpdate()->first();
            $second->beginTransaction();

            $refusal = null;

            try {
                $second->table('sales')->where('id', $sale)->lockForUpdate()->first();
            } catch (QueryException $caught) {
                $refusal = $caught;
            }

            expect($refusal)->toBeInstanceOf(QueryException::class)
                ->and($refusal->getMessage())->toContain('Lock wait timeout');

            expect($refusal->getMessage())->not->toContain('Deadlock');

            $second->rollBack();
        } finally {
            $first->rollBack();
        }
    } finally {
        DB::purge('t030_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('the real DocumentNumbers gives distinct numbers across two sessions, including a first use the second session\'s snapshot cannot see', function () {
    $db = EngineDatabase::engine();
    $tenantName = salesEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = salesEngineTwoSessions();
        ['first' => $first, 'second' => $second] = $sessions;

        $next = static fn (string $connection): string => salesEngineAs($connection, static fn (): string => app(DocumentNumbers::class)->next('sale', null));

        // The second session's snapshot is taken while no `sale` sequence row exists.
        $second->beginTransaction();

        try {
            expect($second->table('number_sequences')->where('document_type', 'sale')->count())->toBe(0);

            $a = $next($sessions['firstName']);

            // The first session created the row and committed; the second finds it by its locking read and takes the next number.
            $b = $next($sessions['secondName']);

            $second->commit();
        } finally {
            if ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
        }

        $c = $next($sessions['firstName']);

        expect([$a, $b, $c])->toBe(['S000001', 'S000002', 'S000003'])
            ->and($first->table('number_sequences')->where('document_type', 'sale')->count())->toBe(1);

        // The wait: the first holds the sequence row, the second times out on it rather than reading a stale number.
        $first->beginTransaction();

        try {
            salesEngineAs($sessions['firstName'], static fn (): string => app(DocumentNumbers::class)->next('sale', null));
            $second->beginTransaction();

            $refusal = null;

            try {
                $next($sessions['secondName']);
            } catch (QueryException $caught) {
                $refusal = $caught;
            }

            expect($refusal)->toBeInstanceOf(QueryException::class)
                ->and($refusal->getMessage())->toContain('Lock wait timeout');

            $second->rollBack();
        } finally {
            $first->rollBack();
        }
    } finally {
        DB::purge('t030_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('a close that holds the shift row makes a second session wait, and once it commits that session sees the shift closed (R-5)', function () {
    $db = EngineDatabase::engine();
    $tenantName = salesEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = salesEngineTwoSessions();
        ['first' => $first, 'second' => $second] = $sessions;
        $terminal = salesEngineId();
        $row = salesEngineShiftRow($terminal);
        $first->table('cash_shifts')->insert($row);

        // The close holds the shift row: a second session's locking read waits and times out.
        $first->beginTransaction();

        try {
            $first->table('cash_shifts')->where('id', $row['id'])->lockForUpdate()->first();
            $second->beginTransaction();

            expect(fn () => $second->table('cash_shifts')->where('id', $row['id'])->lockForUpdate()->first())->toThrow(QueryException::class);

            $second->rollBack();

            $first->table('cash_shifts')->where('id', $row['id'])->update(['status' => 'closed', 'closed_at' => now()]);
            $first->commit();
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        // The session whose snapshot says open now locks the row and reads closed: `no_open_shift`, not a draft on a closed shift.
        $second->beginTransaction();

        try {
            $second->table('cash_shifts')->where('terminal_id', $terminal)->count();

            expect($second->table('cash_shifts')->where('id', $row['id'])->lockForUpdate()->value('status'))->toBe('closed');

            $second->rollBack();
        } finally {
            if ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
        }
    } finally {
        DB::purge('t030_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('two sessions locking the same two discounts in ascending id order both succeed without a deadlock (R-5)', function () {
    $db = EngineDatabase::engine();
    $tenantName = salesEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = salesEngineTwoSessions();
        ['first' => $first, 'second' => $second] = $sessions;

        $ids = [salesEngineId(), salesEngineId()];
        sort($ids);

        foreach ($ids as $index => $id) {
            $first->table('discounts')->insert([
                'id' => $id, 'created_at' => now(), 'updated_at' => now(), 'code' => 'D'.$index, 'name' => 'D'.$index,
                'type' => 'percentage', 'value' => '10.0000', 'scope' => 'item', 'applies_to' => '{}',
                'requires_approval' => 0, 'used_count' => 0, 'usage_limit' => 5, 'is_active' => 1,
            ]);
        }

        $consume = static function (Connection $connection) use ($ids): void {
            $connection->beginTransaction();

            try {
                // The ascending order every writer keeps: the lowest id first.
                foreach ($ids as $id) {
                    $connection->table('discounts')->where('id', $id)->lockForUpdate()->first();
                    $connection->table('discounts')->where('id', $id)->increment('used_count');
                }

                $connection->commit();
            } catch (Throwable $thrown) {
                $connection->rollBack();

                throw $thrown;
            }
        };

        // One after the other: with the order fixed, neither can hold what the other wants while asking for what it holds.
        $consume($first);
        $consume($second);

        expect($first->table('discounts')->pluck('used_count')->map(static fn (mixed $count): int => (int) $count)->all())->toBe([2, 2]);

        // The control: while the first holds the lowest id, the second waits on that same id and times out, not a deadlock.
        $first->beginTransaction();

        try {
            $first->table('discounts')->where('id', $ids[0])->lockForUpdate()->first();

            $refusal = null;

            try {
                $consume($second);
            } catch (QueryException $caught) {
                $refusal = $caught;
            }

            expect($refusal)->toBeInstanceOf(QueryException::class)
                ->and($refusal->getMessage())->toContain('Lock wait timeout');

            expect($refusal->getMessage())->not->toContain('Deadlock');
        } finally {
            $first->rollBack();
        }
    } finally {
        DB::purge('t030_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
