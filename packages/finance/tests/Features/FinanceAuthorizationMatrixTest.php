<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Features\CloseFiscalYear\CloseFiscalYear;
use Flexipos\Finance\Features\CloseFiscalYear\CloseFiscalYearHandler;
use Flexipos\Finance\Features\CreateAccount\CreateAccount;
use Flexipos\Finance\Features\CreateAccount\CreateAccountHandler;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYear;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYearHandler;
use Flexipos\Finance\Features\DeactivateAccount\DeactivateAccount;
use Flexipos\Finance\Features\DeactivateAccount\DeactivateAccountHandler;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccount;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccountHandler;
use Flexipos\Finance\Features\DeleteFiscalYear\DeleteFiscalYear;
use Flexipos\Finance\Features\DeleteFiscalYear\DeleteFiscalYearHandler;
use Flexipos\Finance\Features\FindPeriodByDate\FindPeriodByDate;
use Flexipos\Finance\Features\FindPeriodByDate\FindPeriodByDateHandler;
use Flexipos\Finance\Features\GetAccount\GetAccount;
use Flexipos\Finance\Features\GetAccount\GetAccountHandler;
use Flexipos\Finance\Features\GetAccountTree\GetAccountTree;
use Flexipos\Finance\Features\GetAccountTree\GetAccountTreeHandler;
use Flexipos\Finance\Features\ListAccounts\ListAccounts;
use Flexipos\Finance\Features\ListAccounts\ListAccountsHandler;
use Flexipos\Finance\Features\ListFiscalPeriods\ListFiscalPeriods;
use Flexipos\Finance\Features\ListFiscalPeriods\ListFiscalPeriodsHandler;
use Flexipos\Finance\Features\ListFiscalYears\ListFiscalYears;
use Flexipos\Finance\Features\ListFiscalYears\ListFiscalYearsHandler;
use Flexipos\Finance\Features\ReactivateAccount\ReactivateAccount;
use Flexipos\Finance\Features\ReactivateAccount\ReactivateAccountHandler;
use Flexipos\Finance\Features\RenameFiscalPeriod\RenameFiscalPeriod;
use Flexipos\Finance\Features\RenameFiscalPeriod\RenameFiscalPeriodHandler;
use Flexipos\Finance\Features\RenameFiscalYear\RenameFiscalYear;
use Flexipos\Finance\Features\RenameFiscalYear\RenameFiscalYearHandler;
use Flexipos\Finance\Features\ReopenFiscalYear\ReopenFiscalYear;
use Flexipos\Finance\Features\ReopenFiscalYear\ReopenFiscalYearHandler;
use Flexipos\Finance\Features\ReparentAccount\ReparentAccount;
use Flexipos\Finance\Features\ReparentAccount\ReparentAccountHandler;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriod;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriodHandler;
use Flexipos\Finance\Features\UpdateAccount\UpdateAccount;
use Flexipos\Finance\Features\UpdateAccount\UpdateAccountHandler;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 8 - the AC-64 matrix over every account slice
|--------------------------------------------------------------------------
|
| One dataset, every slice this chunk ships. The period slices have joined it as of
| chunk D. Three facts per slice and per kind of caller:
|
|   - a caller without the key is refused `not_permitted`;
|   - **no statement on `accounts` or `fiscal_*` ran** (authorization is the
|     first thing a handler does, before it opens a transaction or reads its
|     target), proven by a query listener on the tenant connection;
|   - the key's own holder is NOT refused `not_permitted` (the control: the
|     other three would pass against a handler that refuses everyone).
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/finance-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    financeEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Every account slice: the key it needs, and a call that names a real target.
 *
 * @return array<string, array{0: string, 1: callable(?string, string, string, array<string, string>): mixed}>
 */
function financeSlices(): array
{
    return [
        'CreateAccount' => [FinancePermissions::ACCOUNTS_MANAGE, fn (?string $a) => app(CreateAccountHandler::class)->handle(new CreateAccount(['code' => 'X1', 'name' => 'X', 'type' => 'asset'], $a))],
        'UpdateAccount' => [FinancePermissions::ACCOUNTS_MANAGE, fn (?string $a, string $t) => app(UpdateAccountHandler::class)->handle(new UpdateAccount($t, ['name' => 'Y'], $a))],
        'ReparentAccount' => [FinancePermissions::ACCOUNTS_MANAGE, fn (?string $a, string $t, string $h) => app(ReparentAccountHandler::class)->handle(new ReparentAccount($t, $h, $a))],
        'DeactivateAccount' => [FinancePermissions::ACCOUNTS_MANAGE, fn (?string $a, string $t) => app(DeactivateAccountHandler::class)->handle(new DeactivateAccount($t, $a))],
        'ReactivateAccount' => [FinancePermissions::ACCOUNTS_MANAGE, fn (?string $a, string $t) => app(ReactivateAccountHandler::class)->handle(new ReactivateAccount($t, $a))],
        'DeleteAccount' => [FinancePermissions::ACCOUNTS_MANAGE, fn (?string $a, string $t) => app(DeleteAccountHandler::class)->handle(new DeleteAccount($t, $a))],
        'ListAccounts' => [FinancePermissions::ACCOUNTS_VIEW, fn (?string $a) => app(ListAccountsHandler::class)->handle(new ListAccounts([], null, null, null, $a))],
        'GetAccount' => [FinancePermissions::ACCOUNTS_VIEW, fn (?string $a, string $t) => app(GetAccountHandler::class)->handle(new GetAccount($t, $a))],
        'GetAccountTree' => [FinancePermissions::ACCOUNTS_VIEW, fn (?string $a) => app(GetAccountTreeHandler::class)->handle(new GetAccountTree($a))],
        'CreateFiscalYear' => [FinancePermissions::PERIODS_MANAGE, fn (?string $a) => app(CreateFiscalYearHandler::class)->handle(new CreateFiscalYear(['name' => 'FY', 'start_date' => '2031-01-01'], $a))],
        'RenameFiscalYear' => [FinancePermissions::PERIODS_MANAGE, fn (?string $a, string $t, string $h, array $f) => app(RenameFiscalYearHandler::class)->handle(new RenameFiscalYear($f['year'], ['name' => 'Y'], $a))],
        'RenameFiscalPeriod' => [FinancePermissions::PERIODS_MANAGE, fn (?string $a, string $t, string $h, array $f) => app(RenameFiscalPeriodHandler::class)->handle(new RenameFiscalPeriod($f['period'], ['name' => 'P'], $a))],
        'DeleteFiscalYear' => [FinancePermissions::PERIODS_MANAGE, fn (?string $a, string $t, string $h, array $f) => app(DeleteFiscalYearHandler::class)->handle(new DeleteFiscalYear($f['year'], $a))],
        'TransitionFiscalPeriod (close move)' => [FinancePermissions::PERIODS_CLOSE, fn (?string $a, string $t, string $h, array $f) => app(TransitionFiscalPeriodHandler::class)->handle(new TransitionFiscalPeriod($f['period'], ['status' => 'soft_closed', 'expected_status' => 'open'], $a))],
        'TransitionFiscalPeriod (reopen move)' => [FinancePermissions::PERIODS_REOPEN, fn (?string $a, string $t, string $h, array $f) => app(TransitionFiscalPeriodHandler::class)->handle(new TransitionFiscalPeriod($f['period'], ['status' => 'open', 'expected_status' => 'soft_closed', 'reason' => 'r'], $a))],
        'CloseFiscalYear' => [FinancePermissions::PERIODS_CLOSE, fn (?string $a, string $t, string $h, array $f) => app(CloseFiscalYearHandler::class)->handle(new CloseFiscalYear($f['year'], $a))],
        'ReopenFiscalYear' => [FinancePermissions::PERIODS_REOPEN, fn (?string $a, string $t, string $h, array $f) => app(ReopenFiscalYearHandler::class)->handle(new ReopenFiscalYear($f['year'], 'r', $a))],
        'ListFiscalYears' => [FinancePermissions::PERIODS_VIEW, fn (?string $a) => app(ListFiscalYearsHandler::class)->handle(new ListFiscalYears(null, null, $a))],
        'ListFiscalPeriods' => [FinancePermissions::PERIODS_VIEW, fn (?string $a, string $t, string $h, array $f) => app(ListFiscalPeriodsHandler::class)->handle(new ListFiscalPeriods($f['year'], $a))],
        'FindPeriodByDate' => [FinancePermissions::PERIODS_VIEW, fn (?string $a) => app(FindPeriodByDateHandler::class)->handle(new FindPeriodByDate('2031-02-01', $a))],
    ];
}

/**
 * Run a slice and return every SQL statement it issued on the tenant
 * connection.
 *
 * @param  callable(?string, string, string, array<string, string>): mixed  $slice
 * @param  array<string, string>  $fiscal  the year and period ids a period slice targets
 * @return array{0: ?FinanceRefused, 1: list<string>}
 */
function financeRunRecorded(callable $slice, ?string $actor, string $target, string $header, array $fiscal): array
{
    $sql = [];
    FinanceSeed::connection()->listen(static function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    $refused = null;

    try {
        $slice($actor, $target, $header, $fiscal);
    } catch (FinanceRefused $caught) {
        $refused = $caught;
    }

    return [$refused, $sql];
}

function financeTouchesFinanceTables(string $sql): bool
{
    return preg_match('/\b(accounts|fiscal_years|fiscal_periods)\b/i', $sql) === 1;
}

it('refuses a caller without the key before any finance statement runs, for every account slice (AC-64)', function (string $name, string $callerKind) {
    [$key, $slice] = financeSlices()[$name];

    $target = FinanceSeed::account('T1', ['is_active' => false]);
    $header = FinanceSeed::header('H1');
    $calendar = FinanceSeed::fiscalYear('2031-01-01', null, [], [1 => 'soft_closed']);
    $fiscal = ['year' => $calendar['id'], 'period' => $calendar['periods'][1]];

    $actor = match ($callerKind) {
        'no key' => FinanceSeed::nobody(),
        'every other key' => FinanceSeed::actor('others', ...array_values(array_diff(FinancePermissions::KEYS, [$key]))),
        'null actor' => null,
        'deleted holder' => (function () use ($key): string {
            $id = FinanceSeed::actor('ghost', $key);
            FinanceSeed::connection()->table('employees')->where('id', $id)->update(['deleted_at' => FinanceSeed::NOW]);

            return $id;
        })(),
        default => throw new \InvalidArgumentException($callerKind),
    };

    [$refused, $sql] = financeRunRecorded($slice, $actor, $target, $header, $fiscal);

    expect($refused)->not->toBeNull()
        ->and($refused->reason)->toBe(FinanceRefusal::NotPermitted)
        ->and($refused->context)->toBe(['permission' => $key]);

    $touching = array_values(array_filter($sql, financeTouchesFinanceTables(...)));

    expect($touching)->toBe([]);
})->with(function () {
    foreach (array_keys(financeSlices()) as $name) {
        foreach (['no key', 'every other key', 'null actor', 'deleted holder'] as $kind) {
            yield "{$name} / {$kind}" => [$name, $kind];
        }
    }
});

it('lets the holder of exactly the key past authorization, for every account slice (the control)', function (string $name) {
    [$key, $slice] = financeSlices()[$name];

    $target = FinanceSeed::account('T1', ['is_active' => false]);
    $header = FinanceSeed::header('H1');
    $calendar = FinanceSeed::fiscalYear('2031-01-01', null, [], [1 => 'soft_closed']);
    $fiscal = ['year' => $calendar['id'], 'period' => $calendar['periods'][1]];
    $actor = FinanceSeed::actor('holder', $key);

    [$refused, $sql] = financeRunRecorded($slice, $actor, $target, $header, $fiscal);

    // Whatever else happens (success, or a rule refusing the fixture), it is
    // not an authorization refusal, and the slice did reach the table.
    expect($refused?->reason)->not->toBe(FinanceRefusal::NotPermitted)
        ->and(array_values(array_filter($sql, financeTouchesFinanceTables(...))))->not->toBe([]);
})->with(array_keys(financeSlices()));

it('keeps the two view keys and the manage key apart (D-24)', function () {
    $manager = FinanceSeed::actor('manager', FinancePermissions::ACCOUNTS_MANAGE);
    $viewer = FinanceSeed::actor('viewer', FinancePermissions::ACCOUNTS_VIEW);

    // Manage does not read; view does not write.
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(ListAccountsHandler::class)->handle(new ListAccounts([], null, null, null, $manager)));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(CreateAccountHandler::class)->handle(new CreateAccount(['code' => 'X1', 'name' => 'X', 'type' => 'asset'], $viewer)));
});
