<?php

use Flexipos\Contracts\Finance\ChartOfAccounts;
use Flexipos\Contracts\Finance\PeriodGate;
use Flexipos\Contracts\Finance\PeriodGateRefused;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Finance\Features\CreateAccount\CreateAccount;
use Flexipos\Finance\Features\CreateAccount\CreateAccountHandler;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYear;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYearHandler;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriod;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriodHandler;
use Flexipos\Finance\Journal\JournalRows;
use Flexipos\Finance\Journal\JournalWriter;
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
| T-033 step E2 - the journal tables, their CHECKs and their locks, against a
| real MySQL/MariaDB connection. [ENGINE] - NOT VERIFIED while ENGINE_HOLD is set.
|--------------------------------------------------------------------------
|
| Only the first case below runs under the hold. Every other case is written for
| gate G-1 and has NEVER BEEN RUN against an engine, exactly as
| FinanceSchemaEngineTest and SalesSchemaEngineTest were: nothing in this file is
| claimed to pass, and no result of it appears in any report (AC-82). Each is
| skipped with "ENGINE_HOLD (G-1)". Nothing here may reach a real MySQL or MariaDB
| server before the hold is lifted, and the local engine hosts databases that
| belong to other projects: the one database this file touches is created and
| dropped by its **exact name** through EngineDatabase, never by a sweep and never
| by LIKE.
|
| What each case is, and is not.
|
| - The schema cases (widths, collation, the two named CHECKs, migrating twice)
|   are the [ENGINE] halves of criteria the SQLite stand-in cannot prove: it
|   enforces no CHECK and reports no lengths.
| - The CHECK case inserts rows the application never builds (both sides set, a
|   negative side, an unbalanced entry) and expects the engine itself to refuse
|   each on its own.
| - The lock cases are single-process and non-blocking, the shape
|   FinanceSchemaEngineTest uses: one session holds a lock, the other, with
|   `innodb_lock_wait_timeout = 1`, times out on it (a lock wait, told apart from
|   a deadlock by its error text), the first commits and the second proceeds.
|   The second session calls the real gate, the real chart contract, the real
|   allocator or the real JournalRows, so what is shown is the shipped lock and
|   not a restatement of it.
| - Rows are built with explicit ids and with foreign-key checks off on both
|   sessions, so an entry can be written without its whole parent graph (the
|   locks and keys under test are not the foreign keys).
| - **Not built: the deadlock-and-retry case** (plan risk 3, a caller posting
|   twice against a concurrent account admin, one side retried). A deadlock needs
|   two statements blocked on each other at one moment, which one process cannot
|   produce: the second statement would hang until the timeout, and a timeout is
|   not a deadlock. It needs a child process holding one session, and writing
|   that blind for a case that cannot run would ship a test most likely wrong at
|   G-1. The same call was made for T-032's 1213 case. Its engine evidence is
|   owed to FP-262.
| - The two-simultaneous-posts forms (AC-33a, AC-40, AC-57) are shown in their
|   sequential form: the winner's row is committed or locked, and the loser's
|   statement is the one the posting path catches. The unique keys that make the
|   loser lose are shown directly.
*/

function journalEngineTenantName(): string
{
    return 'flexipos_t8test_t033_tenant';
}

function journalEngineMigrate(): string
{
    $name = EngineConnection::connectionName(journalEngineTenantName());
    EngineConnection::connection(journalEngineTenantName());

    expect(Artisan::call('migrate', [
        '--database' => $name,
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    return $name;
}

function journalEngineId(): string
{
    return Str::upper(Str::ulid());
}

/**
 * One migrated database seen through two sessions, foreign-key checks off on both, the shipped chart and year removed so
 * the case builds its own.
 *
 * @return array{first: Connection, second: Connection, firstName: string, secondName: string}
 */
function journalEngineTwoSessions(): array
{
    $firstName = journalEngineMigrate();

    config(['database.connections.t033_second' => config('database.connections.'.$firstName)]);

    $first = DB::connection($firstName);
    $second = DB::connection('t033_second');

    foreach ([$first, $second] as $connection) {
        $connection->statement('set session foreign_key_checks = 0');
    }

    $second->statement('set session innodb_lock_wait_timeout = 1');

    $first->table('journal_lines')->delete();
    $first->table('journal_entries')->delete();
    $first->table('fiscal_periods')->delete();
    $first->table('fiscal_years')->delete();
    $first->table('accounts')->update(['parent_id' => null]);
    $first->table('accounts')->delete();

    return ['first' => $first, 'second' => $second, 'firstName' => $firstName, 'secondName' => 't033_second'];
}

/**
 * @template T
 *
 * @param  callable(): T  $work
 * @return T
 */
function journalEngineAs(string $connectionName, callable $work): mixed
{
    $before = config('tenancy.connection');

    config(['tenancy.connection' => $connectionName]);

    try {
        return $work();
    } finally {
        config(['tenancy.connection' => $before]);
    }
}

function journalEngineExpectLockWait(callable $work): void
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
 * A year through the real handler; its first period id.
 */
function journalEngineYear(Connection $connection, string $session, string $start): string
{
    journalEngineAs($session, static fn () => app(CreateFiscalYearHandler::class)->handle(new CreateFiscalYear(['name' => 'FY'.substr($start, 0, 4), 'start_date' => $start], journalEngineId())));

    return (string) $connection->table('fiscal_periods')->orderBy('start_date')->value('id');
}

function journalEngineAccount(string $session, string $code): string
{
    return journalEngineAs($session, static fn (): string => app(CreateAccountHandler::class)->handle(new CreateAccount(['code' => $code, 'name' => 'Account '.$code, 'type' => 'revenue', 'subtype' => 'sales'], journalEngineId()))->accountId);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function journalEngineEntry(string $period, string $number, array $overrides = []): array
{
    return array_merge([
        'id' => journalEngineId(), 'number' => $number, 'entry_date' => '2031-01-15', 'period_id' => $period, 'branch_id' => null,
        'source_type' => 'manual', 'source_id' => null, 'source_purpose' => null, 'status' => 'posted',
        'total_debit' => '10.0000', 'total_credit' => '10.0000', 'description' => null, 'reversal_of_id' => null, 'reversed_by_id' => null,
        'payload_fingerprint' => str_repeat('a', 64), 'posted_at' => now(), 'posted_by' => null, 'is_auto' => false,
        'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function journalEngineLine(string $entry, string $account, int $lineNo, array $overrides = []): array
{
    return array_merge([
        'id' => journalEngineId(), 'entry_id' => $entry, 'line_no' => $lineNo, 'account_id' => $account,
        'debit' => '10.0000', 'credit' => '0.0000', 'memo' => null, 'branch_id' => null, 'location_id' => null, 'department_id' => null,
        'employee_id' => null, 'customer_id' => null, 'supplier_id' => null, 'product_id' => null, 'created_at' => now(),
    ], $overrides);
}

it('refuses to reach the engine at all while the hold is set', function () {
    expect(fn () => EngineConnection::connection(journalEngineTenantName()))->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('[ENGINE] AC-80: every identifier column is CHAR(26) ascii_bin, every amount DECIMAL(18,4); migrating twice is a no-op', function () {
    $db = EngineDatabase::engine();
    $tenantName = journalEngineTenantName();

    $db->recreate($tenantName);

    try {
        $connectionName = journalEngineMigrate();
        $connection = DB::connection($connectionName);

        $identifiers = [
            'journal_entries' => ['id', 'period_id', 'branch_id', 'source_id', 'reversal_of_id', 'reversed_by_id', 'posted_by', 'created_by'],
            'journal_lines' => ['id', 'entry_id', 'account_id', 'branch_id', 'location_id', 'department_id', 'employee_id', 'customer_id', 'supplier_id', 'product_id'],
        ];

        foreach ($identifiers as $table => $columns) {
            foreach ($columns as $column) {
                $row = $connection->selectOne(
                    'select column_type, collation_name from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
                    [$tenantName, $table, $column]
                );

                expect($row)->not->toBeNull("Expected [{$table}.{$column}] to exist.")
                    ->and(strtolower((string) $row->column_type))->toBe('char(26)', "[{$table}.{$column}]")
                    ->and(strtolower((string) $row->collation_name))->toBe('ascii_bin', "[{$table}.{$column}]");
            }
        }

        foreach ([['journal_entries', 'total_debit'], ['journal_entries', 'total_credit'], ['journal_lines', 'debit'], ['journal_lines', 'credit']] as [$table, $column]) {
            $type = $connection->selectOne(
                'select column_type from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
                [$tenantName, $table, $column]
            );

            expect(strtolower((string) $type->column_type))->toBe('decimal(18,4)', "[{$table}.{$column}]");
        }

        $before = $connection->select('select table_name from information_schema.tables where table_schema = ?', [$tenantName]);

        expect(Artisan::call('migrate', ['--database' => $connectionName, '--path' => (string) config('schema.tenant_migration_path'), '--force' => true]))->toBe(0)
            ->and($connection->select('select table_name from information_schema.tables where table_schema = ?', [$tenantName]))->toEqual($before);
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD (G-1)');

it('[ENGINE] AC-45: both CHECKs are listed, and a both-sided row, a negative row and an unbalanced entry are each refused by the engine', function () {
    $db = EngineDatabase::engine();
    $tenantName = journalEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = journalEngineTwoSessions();
        $connection = $sessions['first'];

        $names = array_map(
            static fn (object $row): string => (string) $row->constraint_name,
            $connection->select('select constraint_name from information_schema.check_constraints where constraint_schema = ?', [$tenantName])
        );

        expect($names)->toContain('journal_lines_debit_credit_one_sided')
            ->and($names)->toContain('journal_entries_total_debit_total_credit_balanced');

        $period = journalEngineYear($connection, $sessions['firstName'], '2031-01-01');
        $account = journalEngineAccount($sessions['firstName'], 'sales-1');

        // An unbalanced entry is refused by its own CHECK.
        expect(fn () => $connection->table('journal_entries')->insert(journalEngineEntry($period, 'JE-900001', ['total_debit' => '10.0000', 'total_credit' => '9.0000'])))
            ->toThrow(QueryException::class);

        $entry = journalEngineEntry($period, 'JE-900002');
        $connection->table('journal_entries')->insert($entry);

        // A line with both sides set, and a line with a negative side.
        expect(fn () => $connection->table('journal_lines')->insert(journalEngineLine($entry['id'], $account, 1, ['debit' => '5.0000', 'credit' => '5.0000'])))
            ->toThrow(QueryException::class)
            ->and(fn () => $connection->table('journal_lines')->insert(journalEngineLine($entry['id'], $account, 2, ['debit' => '-5.0000', 'credit' => '0.0000'])))
            ->toThrow(QueryException::class);

        // The one-sided and balanced shapes the application builds are accepted.
        $connection->table('journal_lines')->insert(journalEngineLine($entry['id'], $account, 3));

        expect($connection->table('journal_lines')->where('entry_id', $entry['id'])->count())->toBe(1);
    } finally {
        DB::purge('t033_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD (G-1)');

it('[ENGINE] AC-33a, AC-39: the number is unique, a null purpose is one key, and different purposes are different keys', function () {
    $db = EngineDatabase::engine();
    $tenantName = journalEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = journalEngineTwoSessions();
        $connection = $sessions['first'];
        $period = journalEngineYear($connection, $sessions['firstName'], '2031-01-01');
        $source = journalEngineId();

        $connection->table('journal_entries')->insert(journalEngineEntry($period, 'JE-900001', ['source_type' => 'sale', 'source_id' => $source]));

        expect(fn () => $connection->table('journal_entries')->insert(journalEngineEntry($period, 'JE-900001')))
            ->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => $connection->table('journal_entries')->insert(journalEngineEntry($period, 'JE-900002', ['source_type' => 'sale', 'source_id' => $source])))
            ->toThrow(UniqueConstraintViolationException::class);

        $connection->table('journal_entries')->insert(journalEngineEntry($period, 'JE-900003', ['source_type' => 'sale', 'source_id' => $source, 'source_purpose' => 'revenue']));
        $connection->table('journal_entries')->insert(journalEngineEntry($period, 'JE-900004', ['source_type' => 'sale', 'source_id' => $source, 'source_purpose' => 'cogs']));

        expect($connection->table('journal_entries')->where('source_id', $source)->count())->toBe(3);
    } finally {
        DB::purge('t033_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD (G-1)');

it('[ENGINE] AC-33a: a number allocation waits on the row the other session holds, then the two numbers differ', function () {
    $db = EngineDatabase::engine();
    $tenantName = journalEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = journalEngineTwoSessions();
        ['first' => $first] = $sessions;

        $next = static fn (string $session): string => journalEngineAs($session, static fn (): string => app(DocumentNumbers::class)->next('journal_entry', null));

        $first->beginTransaction();

        try {
            $one = $next($sessions['firstName']);

            journalEngineExpectLockWait(static fn () => $next($sessions['secondName']));

            $first->commit();
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        $two = $next($sessions['secondName']);

        expect($two)->not->toBe($one)->and($one)->toStartWith('JE-');
    } finally {
        DB::purge('t033_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD (G-1)');

it('[ENGINE] AC-40: an insert for a key the other session holds waits, and after it commits is refused with the unique violation the posting path catches', function () {
    $db = EngineDatabase::engine();
    $tenantName = journalEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = journalEngineTwoSessions();
        ['first' => $first, 'second' => $second] = $sessions;
        $period = journalEngineYear($first, $sessions['firstName'], '2031-01-01');
        $source = journalEngineId();

        $insert = static fn (Connection $connection, string $number) => $connection->table('journal_entries')
            ->insert(journalEngineEntry($period, $number, ['source_type' => 'sale', 'source_id' => $source]));

        $first->beginTransaction();

        try {
            $insert($first, 'JE-900001');

            journalEngineExpectLockWait(static fn () => $insert($second, 'JE-900002'));

            $first->commit();
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        expect(fn () => $insert($second, 'JE-900002'))->toThrow(UniqueConstraintViolationException::class)
            ->and($first->table('journal_entries')->where('source_id', $source)->count())->toBe(1);
    } finally {
        DB::purge('t033_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD (G-1)');

it('[ENGINE] AC-25, AC-26: two postings that check the same accounts in different line orders both proceed, and a concurrent deactivate waits on the shared lock postable holds', function () {
    $db = EngineDatabase::engine();
    $tenantName = journalEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = journalEngineTwoSessions();
        ['first' => $first, 'second' => $second] = $sessions;
        $low = journalEngineAccount($sessions['firstName'], 'sales-1');
        $high = journalEngineAccount($sessions['firstName'], 'sales-2');
        [$low, $high] = $low < $high ? [$low, $high] : [$high, $low];

        $postable = static fn (string $session, string $id) => journalEngineAs($session, static fn () => app(ChartOfAccounts::class)->postable($id));

        $first->beginTransaction();
        $second->beginTransaction();

        try {
            // Shared locks do not conflict: both sessions take both accounts, in ascending order, and neither waits.
            $postable($sessions['firstName'], $low);
            $postable($sessions['firstName'], $high);
            $postable($sessions['secondName'], $low);
            $postable($sessions['secondName'], $high);

            // A third writer, a deactivate of an account a posting has checked, is the one that waits.
            config(['database.connections.t033_third' => config('database.connections.'.$sessions['firstName'])]);
            $third = DB::connection('t033_third');
            $third->statement('set session innodb_lock_wait_timeout = 1');

            journalEngineExpectLockWait(static fn () => $third->table('accounts')->where('id', $low)->update(['is_active' => false]));
        } finally {
            foreach ([$first, $second] as $connection) {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }

            DB::purge('t033_third');
        }
    } finally {
        DB::purge('t033_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD (G-1)');

it('[ENGINE] AC-30: a close waits for the posting that holds the period, then closes, and the next gate is refused period_closed', function () {
    $db = EngineDatabase::engine();
    $tenantName = journalEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = journalEngineTwoSessions();
        ['first' => $first] = $sessions;
        $period = journalEngineYear($first, $sessions['firstName'], '2031-01-01');

        $move = static function (string $to, string $from) use ($sessions, $period): void {
            journalEngineAs($sessions['secondName'], static function () use ($period, $to, $from): void {
                app(TransitionFiscalPeriodHandler::class)->handle(new TransitionFiscalPeriod($period, ['status' => $to, 'expected_status' => $from], journalEngineId()));
            });
        };

        $first->beginTransaction();

        try {
            $clearance = journalEngineAs($sessions['firstName'], static fn () => app(PeriodGate::class)->clear('2031-01-15', null));

            expect($clearance->periodId)->toBe($period);

            journalEngineExpectLockWait(static fn () => $move('soft_closed', 'open'));

            $first->commit();
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        $move('soft_closed', 'open');
        $move('closed', 'soft_closed');

        $first->beginTransaction();

        try {
            $refused = null;

            try {
                journalEngineAs($sessions['firstName'], static fn () => app(PeriodGate::class)->clear('2031-01-15', null));
            } catch (PeriodGateRefused $caught) {
                $refused = $caught;
            }

            expect($refused)->toBeInstanceOf(PeriodGateRefused::class)
                ->and($refused->reason)->toBe(PeriodRefusal::PeriodClosed);
        } finally {
            $first->rollBack();
        }
    } finally {
        DB::purge('t033_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD (G-1)');

it('[ENGINE] AC-57: a second reversal waits on the original the first holds, then finds it reversed and its compare-and-set changes no row', function () {
    $db = EngineDatabase::engine();
    $tenantName = journalEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = journalEngineTwoSessions();
        ['first' => $first] = $sessions;
        $period = journalEngineYear($first, $sessions['firstName'], '2031-01-01');
        $original = journalEngineEntry($period, 'JE-900001');
        $first->table('journal_entries')->insert($original);

        // The status the locking read sees, or null when the row is gone.
        $lock = static fn (string $session): ?string => journalEngineAs($session, static function () use ($original): ?string {
            $row = app(JournalRows::class)->entryForUpdate($original['id']);

            return $row === null ? null : (string) $row->status;
        });
        $flip = static fn (string $session): int => journalEngineAs($session, static fn (): int => app(JournalWriter::class)->markReversed($original['id'], journalEngineId(), now()->format('Y-m-d H:i:s.v')));

        $first->beginTransaction();

        try {
            expect($lock($sessions['firstName']))->toBe('posted');

            journalEngineExpectLockWait(static fn () => $lock($sessions['secondName']));

            expect($flip($sessions['firstName']))->toBe(1);

            $first->commit();
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        expect($lock($sessions['secondName']))->toBe('reversed')
            ->and($flip($sessions['secondName']))->toBe(0);
    } finally {
        DB::purge('t033_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD (G-1)');

it('[ENGINE] AC-64: hasPostings reads through the (account_id, entry_id) index, and a 10-line posting transaction is timed', function () {
    $db = EngineDatabase::engine();
    $tenantName = journalEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = journalEngineTwoSessions();
        $connection = $sessions['first'];
        $period = journalEngineYear($connection, $sessions['firstName'], '2031-01-01');
        $account = journalEngineAccount($sessions['firstName'], 'sales-1');
        $entry = journalEngineEntry($period, 'JE-900001', ['total_debit' => '100.0000', 'total_credit' => '100.0000']);

        $connection->table('journal_entries')->insert($entry);

        // The plan the engine chooses for the existence read the port issues.
        $plan = $connection->select('explain select 1 from journal_lines where account_id = ? limit 1', [$account]);
        $keys = array_map(static fn (object $row): string => strtolower((string) ($row->key ?? $row->Key ?? '')), $plan);

        expect(implode(',', $keys))->toContain('account_id')
            ->and(strtolower((string) ($plan[0]->type ?? '')))->not->toBe('all');

        // Ten lines in one transaction and one statement: the elapsed time is recorded, with a loose ceiling.
        $lines = [];

        for ($line = 1; $line <= 10; $line++) {
            $lines[] = journalEngineLine($entry['id'], $account, $line, $line % 2 === 0 ? ['debit' => '0.0000', 'credit' => '10.0000'] : ['debit' => '10.0000', 'credit' => '0.0000']);
        }

        $started = microtime(true);

        $connection->transaction(static function () use ($connection, $lines): void {
            $connection->table('journal_lines')->insert($lines);
        });

        $elapsed = microtime(true) - $started;

        expect($connection->table('journal_lines')->where('account_id', $account)->count())->toBe(10)
            ->and($elapsed)->toBeLessThan(1.0);
    } finally {
        DB::purge('t033_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD (G-1)');
