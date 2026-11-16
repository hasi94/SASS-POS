<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateCategory\CreateCategoryHandler;
use Flexipos\Catalog\Features\CreateProduct\CreateProductHandler;
use Flexipos\Catalog\Features\UpdateCategory\UpdateCategoryHandler;
use Flexipos\Catalog\Features\UpdateProduct\UpdateProductHandler;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Contracts\Finance\ChartOfAccounts;
use Flexipos\Customers\Features\CreateCustomer\CreateCustomerHandler;
use Flexipos\Customers\Features\UpdateCustomer\UpdateCustomerHandler;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\DeactivateAccount\DeactivateAccount;
use Flexipos\Finance\Features\DeactivateAccount\DeactivateAccountHandler;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccount;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccountHandler;
use Flexipos\Finance\Features\UpdateAccount\UpdateAccount;
use Flexipos\Finance\Features\UpdateAccount\UpdateAccountHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/*
|--------------------------------------------------------------------------
| The owed account columns, end to end through the real referrers
| (T-032 plan step 18; AC-23, AC-24, AC-60)
|--------------------------------------------------------------------------
|
| Finance's own tests bind fake referrers (AC-25). This file binds nothing:
| the real `CatalogAccountReferrers` and `CustomerAccountReferrers`, swept
| from the loaded providers, answer for rows that catalog's and customers'
| own seeds wrote. It lives in the host because it is the one place that may
| see all three packages.
|
| What it proves, with no fake anywhere:
|
| - deleting an account is refused while any record names it, live or
|   soft-deleted, naming the kind and both counts (AC-23);
| - deactivating is refused only by a live record, and a deleted-only
|   referrer does not stop it (AC-24);
| - an account no record names deletes cleanly;
| - the contract resolves with only the contract bound: `ChartOfAccounts`
|   comes out of the container without any provider-order assumption (AC-60).
*/

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
    FinanceSeed::clearSeed();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-16 10:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function oareDelete(string $actor, string $accountId): void
{
    /** @var DeleteAccountHandler $handler */
    $handler = app(DeleteAccountHandler::class);

    $handler->handle(new DeleteAccount($accountId, $actor));
}

function oareDeactivate(string $actor, string $accountId): void
{
    /** @var DeactivateAccountHandler $handler */
    $handler = app(DeactivateAccountHandler::class);

    $handler->handle(new DeactivateAccount($accountId, $actor));
}

/**
 * A revenue account, a category and a unit, so a product can name it.
 *
 * @return array{account: string, category: string, unit: string}
 */
function oareSeed(): array
{
    return [
        'account' => FinanceSeed::account('R1', ['type' => 'revenue', 'subtype' => 'sales', 'normal_balance' => 'credit']),
        'category' => CatalogSeed::category('drinks'),
        'unit' => CatalogSeed::unit('each'),
    ];
}

it('resolves the contract with only the contract bound, whatever order the providers booted in (AC-60)', function () {
    expect(app(ChartOfAccounts::class))->toBeInstanceOf(ChartOfAccounts::class);

    // The handlers that need it resolve too: catalog and customers never name Finance's classes.
    foreach ([
        CreateProductHandler::class,
        UpdateProductHandler::class,
        CreateCategoryHandler::class,
        UpdateCategoryHandler::class,
        CreateCustomerHandler::class,
        UpdateCustomerHandler::class,
    ] as $handler) {
        expect(app($handler))->toBeInstanceOf($handler);
    }
});

it('refuses to delete an account a soft-deleted product names, giving 0 live and 1 deleted (AC-23)', function () {
    $actor = FinanceSeed::admin();
    $seed = oareSeed();

    CatalogSeed::product('p1', $seed['category'], $seed['unit'], ['income_account_id' => $seed['account'], 'deleted_at' => CatalogSeed::NOW]);

    $refused = FinanceRefusals::expect(FinanceRefusal::InUse, fn () => oareDelete($actor, $seed['account']));

    expect($refused->context['referrers']['products.income_account_id'])->toBe(['live' => 0, 'deleted' => 1])
        ->and(FinanceSeed::row($seed['account'])['deleted_at'])->toBeNull();
});

it('refuses to delete an account a category or a customer names, live or deleted (AC-23)', function () {
    $actor = FinanceSeed::admin();
    $income = FinanceSeed::account('R1', ['type' => 'revenue', 'subtype' => 'sales', 'normal_balance' => 'credit']);
    $receivable = FinanceSeed::account('A1', ['subtype' => 'receivable']);

    CatalogSeed::category('c1', null, ['income_account_id' => $income]);
    CustomersSeed::customer('Gone', ['receivable_account_id' => $receivable, 'deleted_at' => CustomersSeed::NOW]);

    $first = FinanceRefusals::expect(FinanceRefusal::InUse, fn () => oareDelete($actor, $income));
    $second = FinanceRefusals::expect(FinanceRefusal::InUse, fn () => oareDelete($actor, $receivable));

    expect($first->context['referrers']['categories.income_account_id'])->toBe(['live' => 1, 'deleted' => 0])
        ->and($second->context['referrers']['customers.receivable_account_id'])->toBe(['live' => 0, 'deleted' => 1]);
});

it('deactivates an account only a deleted record names, and refuses one a live record names (AC-24)', function () {
    $actor = FinanceSeed::admin();
    $seed = oareSeed();
    $other = FinanceSeed::account('R2', ['type' => 'revenue', 'subtype' => 'sales', 'normal_balance' => 'credit']);

    CatalogSeed::product('p1', $seed['category'], $seed['unit'], ['income_account_id' => $seed['account'], 'deleted_at' => CatalogSeed::NOW]);
    CatalogSeed::product('p2', $seed['category'], $seed['unit'], ['income_account_id' => $other]);

    oareDeactivate($actor, $seed['account']);

    expect((bool) FinanceSeed::row($seed['account'])['is_active'])->toBeFalse();

    $refused = FinanceRefusals::expect(FinanceRefusal::InUse, fn () => oareDeactivate($actor, $other));

    expect($refused->context['referrers']['products.income_account_id']['live'])->toBe(1)
        ->and((bool) FinanceSeed::row($other)['is_active'])->toBeTrue();
});

it('refuses an update that retypes an account a live product names (AC-15 through the real referrers)', function () {
    $actor = FinanceSeed::admin();
    $seed = oareSeed();

    CatalogSeed::product('p1', $seed['category'], $seed['unit'], ['income_account_id' => $seed['account']]);

    /** @var UpdateAccountHandler $handler */
    $handler = app(UpdateAccountHandler::class);

    $refused = FinanceRefusals::capture(fn () => $handler->handle(new UpdateAccount($seed['account'], ['type' => 'expense'], $actor)));

    expect($refused->reason)->toBe(FinanceRefusal::TypeChangeRefused)
        ->and(FinanceSeed::row($seed['account'])['type'])->toBe('revenue');
});

it('deletes an account no record names', function () {
    $actor = FinanceSeed::admin();
    $seed = oareSeed();

    oareDelete($actor, $seed['account']);

    expect(FinanceSeed::row($seed['account'])['deleted_at'])->not->toBeNull();
});
