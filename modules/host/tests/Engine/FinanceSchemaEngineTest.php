<?php

use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Finance\AccountKind;
use Flexipos\Contracts\Finance\ChartOfAccounts;
use Flexipos\Contracts\Finance\PeriodGate;
use Flexipos\Contracts\Finance\PeriodGateRefused;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Features\CreateAccount\CreateAccount;
use Flexipos\Finance\Features\CreateAccount\CreateAccountHandler;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYear;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYearHandler;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccount;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccountHandler;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriod;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriodHandler;
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
| T-032 step 20 - the finance tables and their locks, against a real
| MySQL/MariaDB connection. NOT VERIFIED while ENGINE_HOLD is set.
|--------------------------------------------------------------------------
|
| Only the first case below runs under the hold. Everything after it is written
| for gate G-1 and has NEVER BEEN RUN against an engine, exactly as
| SalesSchemaEngineTest and PricingSchemaEngineTest were: nothing in this file is
| claimed to pass, and no result of it appears in any report. Nothing here may
| reach a real MySQL or MariaDB server before the hold is lifted, and the local
| engine hosts databases belonging to other projects: the one database this file
| touches is created and dropped by its **exact name** through EngineDatabase,
| never by a sweep and never by LIKE.
|
| What each case is, and is not.
|
| - The schema cases (the decimal shape, the generated control marker, the
|   code collation, migrating twice) are the `[ENGINE]` halves of criteria the
|   SQLite stand-in cannot prove: it compiles no generated-column uniqueness the
|   way MySQL does and compares text on its own collation.
| - The lock cases are **single-process and non-blocking**, the shape
|   SalesSchemaEngineTest uses: one session holds a lock and the other session,
|   with `innodb_lock_wait_timeout = 1`, times out on it, which is a lock wait
|   and is told apart from a deadlock by its error text. The first session then
|   commits and the second proceeds. The second session calls the **real**
|   handler or gate, through the tenant connection pointed at it, so what is
|   shown is the handler's own lock order and not a re-statement of it.
| - Rows are built with explicit ids, never `insertGetId` (T-028 W-2), and with
|   the foreign-key checks off on both sessions so a row can be written without
|   its whole parent graph (the locks and keys under test are not the foreign
|   keys).
| - **Not built: the 1213 case** (plan risk 3, a re-parent against a concurrent
|   child create, or an account delete against a product update that already
|   references the account, then a clean retry through `transaction(..., 2)`).
|   A deadlock needs two statements blocked on each other at the same moment,
|   which a single process cannot produce: the second statement would simply
|   hang until the timeout, and a timeout is not a deadlock. Building it needs a
|   child process holding one session, and writing that blind, for a case that
|   cannot run, would ship a test most likely wrong at G-1. The retry's shape is
|   the one the stand-in tests prove with an injected deadlock; its engine
|   evidence is still owed.
| - The two-first-year race (AC-35) is shown in its **sequential** form: the
|   second create finds the first's committed year by its locking read and is
|   refused `fiscal_year_not_contiguous`, overlapping and then gapped. The
|   simultaneous form (both sessions find no year) has the same single-process
|   limit as the 1213 case, and the unique indexes on the two start dates are
|   shown directly by the schema case.
*/

function financeEngineTenantName(): string
{
    return 'flexipos_t8test_t032_tenant';
}

/**
 * Migrate the tenant tree into the fixture database and return its connection name.
 */
function financeEngineMigrate(): string
{
    $name = EngineConnection::connectionName(financeEngineTenantName());
    EngineConnection::connection(financeEngineTenantName());

    expect(Artisan::call('migrate', [
        '--database' => $name,
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    return $name;
}

function financeEngineId(): string
{
    return Str::upper(Str::ulid());
}

/**
 * One migrated database seen through two connections, foreign-key checks off on both, the shipped seed (the chart and
 * the current year) removed so each case starts from empty tables, and the permission resolver bound to one that allows
 * everything (the resolver is not what this file is about).
 *
 * @return array{first: Connection, second: Connection, firstName: string, secondName: string}
 */
function financeEngineTwoSessions(): array
{
    $firstName = financeEngineMigrate();

    config(['database.connections.t032_second' => config('database.connections.'.$firstName)]);

    $first = DB::connection($firstName);
    $second = DB::connection('t032_second');

    foreach ([$first, $second] as $connection) {
        $connection->statement('set session foreign_key_checks = 0');
    }

    $second->statement('set session innodb_lock_wait_timeout = 1');

    $first->table('fiscal_periods')->delete();
    $first->table('fiscal_years')->delete();
    $first->table('accounts')->update(['parent_id' => null]);
    $first->table('accounts')->delete();

    app()->instance(PermissionResolver::class, new class implements PermissionResolver
    {
        public function decide(string $employeeId, string $permissionKey, ?string $branchId): AccessDecision
        {
            return AccessDecision::allow();
        }
    });

    return ['first' => $first, 'second' => $second, 'firstName' => $firstName, 'secondName' => 't032_second'];
}

/**
 * Run a call with the tenant connection pointed at one session.
 *
 * @template T
 *
 * @param  callable(): T  $work
 * @return T
 */
function financeEngineAs(string $connectionName, callable $work): mixed
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
 * @param  array<string, mixed>  $fields
 */
function financeEngineCreateAccount(string $session, array $fields): string
{
    return financeEngineAs($session, static fn (): string => app(CreateAccountHandler::class)->handle(new CreateAccount($fields, financeEngineId()))->accountId);
}

/**
 * The refusal a callable ends in, or null when it does not refuse.
 */
function financeEngineRefusal(callable $work): ?FinanceRefusal
{
    try {
        $work();
    } catch (FinanceRefused $refused) {
        return $refused->reason;
    }

    return null;
}

/**
 * Assert that a call is blocked on a lock the other session holds: a lock wait timeout, not a deadlock.
 */
function financeEngineExpectLockWait(callable $work): void
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
function financeEngineAccountRow(string $code, array $overrides = []): array
{
    $id = $overrides['id'] ?? financeEngineId();

    return array_merge([
        'id' => $id, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null, 'created_by' => null, 'updated_by' => null,
        'code' => $code, 'name' => 'Account '.$code, 'description' => null, 'type' => 'asset', 'subtype' => null, 'parent_id' => null,
        'path' => $id, 'depth' => 0, 'normal_balance' => 'debit', 'is_postable' => true, 'is_system' => false, 'control_type' => 'none',
        'currency' => null, 'is_active' => true, 'sort_order' => 0, 'opening_balance' => null, 'opening_balance_date' => null,
    ], $overrides);
}

/**
 * One period transition through the real handler, on one session.
 */
function financeEngineTransition(string $session, string $period, string $to, string $from): void
{
    financeEngineAs($session, static function () use ($period, $to, $from): void {
        app(TransitionFiscalPeriodHandler::class)->handle(
            new TransitionFiscalPeriod($period, ['status' => $to, 'expected_status' => $from], financeEngineId())
        );
    });
}

/**
 * A year through the real handler, and its twelve period ids by sequence.
 *
 * @return array{year: string, periods: list<string>}
 */
function financeEngineCreateYear(Connection $connection, string $session, string $name, string $start): array
{
    financeEngineAs($session, static fn () => app(CreateFiscalYearHandler::class)->handle(new CreateFiscalYear(['name' => $name, 'start_date' => $start], financeEngineId())));

    $year = (string) $connection->table('fiscal_years')->where('start_date', $start)->value('id');

    /** @var list<string> $periods */
    $periods = $connection->table('fiscal_periods')->where('fiscal_year_id', $year)->orderBy('start_date')->pluck('id')->all();

    return ['year' => $year, 'periods' => $periods];
}

/**
 * `column => [precision, scale]` for every decimal the accounts migration declares, read from the file itself so the
 * expectation cannot drift from it.
 *
 * @return array<string, array{0: int, 1: int}>
 */
function financeEngineDeclaredDecimals(): array
{
    $file = base_path((string) config('schema.tenant_migration_path')).'/2026_11_15_003201_create_accounts_table.php';
    $code = SourceRules::codeOnly((string) file_get_contents($file));
    $declared = [];

    preg_match_all('/->\s*(?:decimal|unsignedDecimal)\s*\(\s*[\'"](\w+)[\'"]\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/', $code, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $declared[$match[1]] = [(int) $match[2], (int) $match[3]];
    }

    return $declared;
}

it('refuses to reach the engine at all while the hold is set', function () {
    expect(fn () => EngineConnection::connection(financeEngineTenantName()))->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('applies the three finance tables with the declared decimal, and migrating twice is a no-op', function () {
    $db = EngineDatabase::engine();
    $tenantName = financeEngineTenantName();

    $db->recreate($tenantName);

    try {
        $connectionName = financeEngineMigrate();
        $declared = financeEngineDeclaredDecimals();

        expect($declared)->toHaveKey('opening_balance');

        /** @var array<int, object{table_name: string, column_name: string, column_type: string}> $columns */
        $columns = DB::connection($connectionName)->select(
            'select table_name, column_name, column_type from information_schema.columns where table_schema = ? and table_name = ? and data_type = ?',
            [$tenantName, 'accounts', 'decimal']
        );

        $seen = [];

        foreach ($columns as $column) {
            $seen[strtolower($column->column_name)] = strtolower($column->column_type);
        }

        foreach ($declared as $column => [$precision, $scale]) {
            expect($seen[$column] ?? null)->toBe("decimal({$precision},{$scale})", "Expected [accounts.{$column}] to be decimal({$precision},{$scale}).");
        }

        $before = DB::connection($connectionName)->select('select table_name from information_schema.tables where table_schema = ?', [$tenantName]);

        expect(Artisan::call('migrate', ['--database' => $connectionName, '--path' => (string) config('schema.tenant_migration_path'), '--force' => true]))->toBe(0)
            ->and(DB::connection($connectionName)->select('select table_name from information_schema.tables where table_schema = ?', [$tenantName]))->toEqual($before);
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('AC-3, AC-17: the code index refuses ab1 against AB1 on the real collation, and the generated control marker holds one live holder', function () {
    $db = EngineDatabase::engine();
    $tenantName = financeEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = financeEngineTwoSessions();
        $connection = $sessions['first'];

        $connection->table('accounts')->insert(financeEngineAccountRow('AB1'));

        // The application folds to upper case, so a lower-case code reaches the index only through a raw insert; the real
        // collation compares it equal to the stored form and the unique index refuses it.
        expect(fn () => $connection->table('accounts')->insert(financeEngineAccountRow('ab1')))->toThrow(UniqueConstraintViolationException::class);

        // A soft-deleted account keeps its code reserved (AC-3).
        $connection->table('accounts')->where('code', 'AB1')->update(['deleted_at' => now()]);

        expect(fn () => $connection->table('accounts')->insert(financeEngineAccountRow('AB1')))->toThrow(UniqueConstraintViolationException::class);

        // A second live `ar` is refused by the generated marker; a soft-deleted holder and a different single type are not.
        $ar = ['type' => 'asset', 'subtype' => 'receivable', 'control_type' => 'ar'];

        $connection->table('accounts')->insert(financeEngineAccountRow('AR1', $ar));

        expect(fn () => $connection->table('accounts')->insert(financeEngineAccountRow('AR2', $ar)))->toThrow(UniqueConstraintViolationException::class);

        $connection->table('accounts')->where('code', 'AR1')->update(['deleted_at' => now()]);
        $connection->table('accounts')->insert(financeEngineAccountRow('AR2', $ar));
        $connection->table('accounts')->insert(financeEngineAccountRow('AP1', ['type' => 'liability', 'subtype' => 'payable', 'control_type' => 'ap', 'normal_balance' => 'credit']));

        expect($connection->table('accounts')->whereNull('deleted_at')->whereIn('control_type', ['ar', 'ap'])->count())->toBe(2);

        // Both start-date indexes exist: the period index is the complete overlap backstop (every period starts on the 1st).
        $connection->table('fiscal_years')->insert(['id' => financeEngineId(), 'created_at' => now(), 'updated_at' => now(), 'name' => 'A', 'start_date' => '2031-01-01', 'end_date' => '2031-12-31', 'status' => 'open']);

        expect(fn () => $connection->table('fiscal_years')->insert(['id' => financeEngineId(), 'created_at' => now(), 'updated_at' => now(), 'name' => 'B', 'start_date' => '2031-01-01', 'end_date' => '2031-12-31', 'status' => 'open']))
            ->toThrow(UniqueConstraintViolationException::class);
    } finally {
        DB::purge('t032_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('AC-17: a create whose snapshot predates a committed ar is refused control_account_exists by the handler\'s locking read, leaving one live holder', function () {
    $db = EngineDatabase::engine();
    $tenantName = financeEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = financeEngineTwoSessions();
        ['first' => $first, 'second' => $second] = $sessions;
        $ar = ['name' => 'Receivables', 'type' => 'asset', 'subtype' => 'receivable', 'control_type' => 'ar'];

        // The first session fixes its snapshot while no `ar` exists.
        $first->beginTransaction();

        try {
            expect($first->table('accounts')->whereNull('deleted_at')->where('control_type', 'ar')->count())->toBe(0);

            // The second session creates the control account and commits.
            financeEngineCreateAccount($sessions['secondName'], ['code' => 'ar'] + $ar);

            // The first's handler pre-check is a locking read, so it sees the committed holder whatever its snapshot.
            $refusal = financeEngineRefusal(static fn () => financeEngineCreateAccount($sessions['firstName'], ['code' => 'ar2'] + $ar));

            expect($refusal)->toBe(FinanceRefusal::ControlAccountExists);
        } finally {
            $first->rollBack();
        }

        expect($second->table('accounts')->whereNull('deleted_at')->where('control_type', 'ar')->count())->toBe(1);
    } finally {
        DB::purge('t032_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('AC-12, AC-21: a create under a parent waits while the parent is being turned postable, then is refused parent_not_header', function () {
    $db = EngineDatabase::engine();
    $tenantName = financeEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = financeEngineTwoSessions();
        ['first' => $first] = $sessions;

        $parent = financeEngineCreateAccount($sessions['firstName'], ['code' => 'cash-hdr', 'name' => 'Cash', 'type' => 'asset', 'is_postable' => false]);

        // A holds the parent FOR UPDATE and turns it postable, uncommitted.
        $first->beginTransaction();

        try {
            $first->table('accounts')->where('id', $parent)->lockForUpdate()->first();
            $first->table('accounts')->where('id', $parent)->update(['is_postable' => true]);

            financeEngineExpectLockWait(static fn () => financeEngineCreateAccount($sessions['secondName'], ['code' => 'cash-1', 'name' => 'Till', 'type' => 'asset', 'parent_id' => $parent]));

            $first->commit();
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        $refusal = financeEngineRefusal(static fn () => financeEngineCreateAccount($sessions['secondName'], ['code' => 'cash-1', 'name' => 'Till', 'type' => 'asset', 'parent_id' => $parent]));

        expect($refusal)->toBe(FinanceRefusal::ParentNotHeader)
            ->and($first->table('accounts')->where('parent_id', $parent)->count())->toBe(0);
    } finally {
        DB::purge('t032_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('AC-35: a second year create finds the committed year by its locking read, and an overlapping or a gapped start leaves exactly one year', function () {
    $db = EngineDatabase::engine();
    $tenantName = financeEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = financeEngineTwoSessions();
        ['first' => $first] = $sessions;

        financeEngineCreateYear($first, $sessions['firstName'], 'FY2031', '2031-01-01');

        foreach (['2031-07-01', '2033-01-01'] as $start) {
            $refusal = financeEngineRefusal(static fn () => financeEngineAs(
                $sessions['secondName'],
                static fn () => app(CreateFiscalYearHandler::class)->handle(new CreateFiscalYear(['name' => 'Other '.$start, 'start_date' => $start], financeEngineId()))
            ));

            expect($refusal)->toBe(FinanceRefusal::FiscalYearNotContiguous);
        }

        expect($first->table('fiscal_years')->count())->toBe(1)
            ->and($first->table('fiscal_periods')->count())->toBe(12);
    } finally {
        DB::purge('t032_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('AC-44: two closes of one period give one winner and one status_changed', function () {
    $db = EngineDatabase::engine();
    $tenantName = financeEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = financeEngineTwoSessions();
        ['first' => $first] = $sessions;
        $calendar = financeEngineCreateYear($first, $sessions['firstName'], 'FY2031', '2031-01-01');
        $period = $calendar['periods'][0];

        $close = static function (string $session) use ($period): void {
            financeEngineTransition($session, $period, 'soft_closed', 'open');
        };

        // Both sessions read the period as open; the first commits its close, the second's compare-and-set finds it changed.
        expect(financeEngineRefusal(static fn () => $close($sessions['firstName'])))->toBeNull()
            ->and(financeEngineRefusal(static fn () => $close($sessions['secondName'])))->toBe(FinanceRefusal::StatusChanged)
            ->and($first->table('fiscal_periods')->where('id', $period)->value('status'))->toBe('soft_closed');
    } finally {
        DB::purge('t032_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('AC-50: a close waits on the shared lock the gate holds, then closes, and the next gate is refused period_closed', function () {
    $db = EngineDatabase::engine();
    $tenantName = financeEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = financeEngineTwoSessions();
        ['first' => $first] = $sessions;
        $calendar = financeEngineCreateYear($first, $sessions['firstName'], 'FY2031', '2031-01-01');
        $period = $calendar['periods'][0];

        $move = static function (string $session, string $to, string $from) use ($period): void {
            financeEngineTransition($session, $period, $to, $from);
        };

        // A runs the gate inside its own transaction and holds the shared lock on the period.
        $first->beginTransaction();

        try {
            $clearance = financeEngineAs($sessions['firstName'], static fn () => app(PeriodGate::class)->clear('2031-01-15', null));

            expect($clearance->periodId)->toBe($period);

            // B's close needs the period FOR UPDATE and waits.
            financeEngineExpectLockWait(static fn () => $move($sessions['secondName'], 'soft_closed', 'open'));

            $first->commit();
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        $move($sessions['secondName'], 'soft_closed', 'open');
        $move($sessions['secondName'], 'closed', 'soft_closed');

        // C's gate, a posting that starts after the close commits, sees it.
        $first->beginTransaction();

        try {
            $refused = null;

            try {
                financeEngineAs($sessions['firstName'], static fn () => app(PeriodGate::class)->clear('2031-01-15', null));
            } catch (PeriodGateRefused $caught) {
                $refused = $caught;
            }

            expect($refused)->toBeInstanceOf(PeriodGateRefused::class)
                ->and($refused->reason)->toBe(PeriodRefusal::PeriodClosed);
        } finally {
            $first->rollBack();
        }
    } finally {
        DB::purge('t032_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('AC-57: a delete waits on the shared lock an assignment holds, then is refused in_use once the assignment commits', function () {
    $db = EngineDatabase::engine();
    $tenantName = financeEngineTenantName();

    $db->recreate($tenantName);

    try {
        $sessions = financeEngineTwoSessions();
        ['first' => $first] = $sessions;

        $account = financeEngineCreateAccount($sessions['firstName'], ['code' => 'sales-1', 'name' => 'Sales', 'type' => 'revenue', 'subtype' => 'sales']);
        $delete = static fn () => financeEngineAs($sessions['secondName'], static fn () => app(DeleteAccountHandler::class)->handle(new DeleteAccount($account, financeEngineId())));

        // A assigns the account to a category, inside its transaction, through the real contract, and holds.
        $first->beginTransaction();

        try {
            financeEngineAs($sessions['firstName'], static fn () => app(ChartOfAccounts::class)->assignable('income_account_id', $account, AccountKind::Income));

            $category = financeEngineId();

            $first->table('categories')->insert([
                'id' => $category, 'created_at' => now(), 'updated_at' => now(), 'code' => 'C'.Str::random(6), 'name' => 'Drinks',
                'path' => $category, 'depth' => 0, 'income_account_id' => $account,
            ]);

            financeEngineExpectLockWait($delete);

            $first->commit();
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
        }

        expect(financeEngineRefusal($delete))->toBe(FinanceRefusal::InUse)
            ->and($first->table('accounts')->where('id', $account)->whereNull('deleted_at')->count())->toBe(1);
    } finally {
        DB::purge('t032_second');
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
