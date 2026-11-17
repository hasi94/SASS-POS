<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Support;

use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Flexipos\Contracts\Finance\AccountReferrer;
use Flexipos\Contracts\Finance\AccountUsage;
use Flexipos\Contracts\Finance\FiscalYearUsage;
use Flexipos\Contracts\Finance\PeriodCloseBlockers;
use Flexipos\Finance\Accounts\ReferrerCensus;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\FinanceServiceProvider;
use Flexipos\Finance\Journal\Ports\JournalAccountUsage;
use Flexipos\Finance\Journal\Ports\JournalFiscalYearUsage;
use Flexipos\Finance\Journal\Ports\JournalPeriodCloseBlockers;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 8 - permissions, authorization, ports (AC-64, AC-25 wiring)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/finance-fixture.php';

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

it('declares exactly the ten keys, none branch-scoped, all in the finance module', function () {
    $declarations = FinancePermissions::declarations();

    expect(array_map(static fn (PermissionDeclaration $d): string => $d->key, $declarations))->toBe([
        'finance.accounts.view',
        'finance.accounts.manage',
        'finance.periods.view',
        'finance.periods.manage',
        'finance.periods.close',
        'finance.periods.reopen',
        'finance.periods.post_adjustment',
        'finance.journal.view',
        'finance.journal.post_manual',
        'finance.journal.reverse',
    ])->and(FinancePermissions::KEYS)->toBe(array_map(static fn (PermissionDeclaration $d): string => $d->key, $declarations));

    foreach ($declarations as $declaration) {
        expect($declaration->module)->toBe('finance')
            ->and($declaration->isBranchScoped)->toBeFalse();
    }
});

it('is found by the registry sweep through the booted provider', function () {
    /** @var PermissionRegistry $registry */
    $registry = app(PermissionRegistry::class);

    expect($registry->providerNames())->toContain(FinanceServiceProvider::class)
        ->and($registry->keysByProvider()[FinanceServiceProvider::class])->toBe([
            'finance.accounts.manage',
            'finance.accounts.view',
            'finance.journal.post_manual',
            'finance.journal.reverse',
            'finance.journal.view',
            'finance.periods.close',
            'finance.periods.manage',
            'finance.periods.post_adjustment',
            'finance.periods.reopen',
            'finance.periods.view',
        ]);
});

it('declares the three journal keys under the journal group and seeds them into no role (T-033 AC-72)', function () {
    $journal = array_values(array_filter(
        FinancePermissions::declarations(),
        static fn (PermissionDeclaration $d): bool => str_starts_with($d->key, 'finance.journal.'),
    ));

    expect(array_map(static fn (PermissionDeclaration $d): string => $d->key, $journal))->toBe([
        FinancePermissions::JOURNAL_VIEW,
        FinancePermissions::JOURNAL_POST_MANUAL,
        FinancePermissions::JOURNAL_REVERSE,
    ]);

    foreach ($journal as $declaration) {
        expect($declaration->group)->toBe('journal')->and($declaration->module)->toBe('finance');
    }

    // No role seed here (the roles task grants them): a fresh tenant holds no journal key in any role.
    expect(FinanceSeed::connection()->table('role_permissions')->where('permission_key', 'like', 'finance.journal.%')->count())->toBe(0);
});

it('allows a holder of the key and refuses everyone else not_permitted', function () {
    $holder = FinanceSeed::actor('holder', FinancePermissions::ACCOUNTS_VIEW);
    $other = FinanceSeed::actor('other', FinancePermissions::ACCOUNTS_MANAGE);

    /** @var Authorize $authorize */
    $authorize = app(Authorize::class);

    $authorize->require($holder, FinancePermissions::ACCOUNTS_VIEW);

    $refused = FinanceRefusals::expect(
        FinanceRefusal::NotPermitted,
        fn () => $authorize->require($other, FinancePermissions::ACCOUNTS_VIEW),
    );

    expect($refused->context)->toBe(['permission' => FinancePermissions::ACCOUNTS_VIEW]);
});

it('refuses a null actor, an unknown employee and a soft-deleted employee', function () {
    $holder = FinanceSeed::actor('holder', FinancePermissions::ACCOUNTS_VIEW);

    /** @var Authorize $authorize */
    $authorize = app(Authorize::class);

    $authorize->require($holder, FinancePermissions::ACCOUNTS_VIEW);

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => $authorize->require(null, FinancePermissions::ACCOUNTS_VIEW));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => $authorize->require((string) Str::ulid(), FinancePermissions::ACCOUNTS_VIEW));

    FinanceSeed::connection()->table('employees')->where('id', $holder)->update(['deleted_at' => FinanceSeed::NOW]);

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => $authorize->require($holder, FinancePermissions::ACCOUNTS_VIEW));
});

it('does not let close grant reopen, or reopen grant close', function () {
    $closer = FinanceSeed::actor('closer', FinancePermissions::PERIODS_CLOSE);
    $reopener = FinanceSeed::actor('reopener', FinancePermissions::PERIODS_REOPEN);

    /** @var Authorize $authorize */
    $authorize = app(Authorize::class);

    $authorize->require($closer, FinancePermissions::PERIODS_CLOSE);
    $authorize->require($reopener, FinancePermissions::PERIODS_REOPEN);

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => $authorize->require($closer, FinancePermissions::PERIODS_REOPEN));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => $authorize->require($reopener, FinancePermissions::PERIODS_CLOSE));
});

it('keeps the two view keys apart (D-24)', function () {
    $accounts = FinanceSeed::actor('accounts-viewer', FinancePermissions::ACCOUNTS_VIEW);
    $periods = FinanceSeed::actor('periods-viewer', FinancePermissions::PERIODS_VIEW);

    /** @var Authorize $authorize */
    $authorize = app(Authorize::class);

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => $authorize->require($accounts, FinancePermissions::PERIODS_VIEW));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => $authorize->require($periods, FinancePermissions::ACCOUNTS_VIEW));
});

it('binds the three usage ports to the journal classes (T-033 AC-62), and an empty journal reports nothing', function () {
    expect(app(AccountUsage::class))->toBeInstanceOf(JournalAccountUsage::class)
        ->and(app(FiscalYearUsage::class))->toBeInstanceOf(JournalFiscalYearUsage::class)
        ->and(app(PeriodCloseBlockers::class))->toBeInstanceOf(JournalPeriodCloseBlockers::class);

    $id = (string) Str::ulid();

    expect(app(AccountUsage::class)->hasPostings($id))->toBeFalse()
        ->and(app(FiscalYearUsage::class)->hasEntries($id, '2026-01-01', '2026-12-31'))->toBeFalse()
        ->and(app(PeriodCloseBlockers::class)->blockersFor($id, '2026-01-01', '2026-01-31'))->toBe([]);
});

it('sums nothing when no record names the account, and everything a bound fake reports on top of the real referrers', function () {
    $id = (string) Str::ulid();

    /** @var ReferrerCensus $census */
    $census = app(ReferrerCensus::class);

    // Catalog and customers declare real referrers now (T-032 step 18): they report zero, not nothing.
    expect($census->of($id))->not->toBe([])
        ->and($census->kinds($id))->toBe([])
        ->and($census->total($id))->toBe(0);

    FakeUsagePorts::bind();
    FakeUsagePorts::$referrers[$id] = [
        new AccountReferrer('products.income_account_id', 2, 1),
        new AccountReferrer('customers.receivable_account_id', 0, 3),
    ];

    $found = app(ReferrerCensus::class)->of($id);

    expect(array_values(array_filter($found, static fn (AccountReferrer $r): bool => $r->total() > 0)))->toHaveCount(2)
        ->and(app(ReferrerCensus::class)->total($id))->toBe(6)
        ->and(app(ReferrerCensus::class)->live($id))->toBe(2);
});
