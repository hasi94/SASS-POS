<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\ReactivateAccount;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\ReactivateAccount\ReactivateAccount;
use Flexipos\Finance\Features\ReactivateAccount\ReactivateAccountHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 11 - reactivating an account (AC-24)
|--------------------------------------------------------------------------
|
| The full deactivate and reactivate cases are in
| `DeactivateAccount/DeactivateAccountTest.php`; this folder mirrors the
| slice (the vertical-slice layout rule).
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/finance-fixture.php';

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

it('reactivates an inactive account and refuses an active one', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('A1', ['is_active' => false]);

    $handler = app(ReactivateAccountHandler::class);
    $handler->handle(new ReactivateAccount($id, $actor));

    expect(FinanceSeed::row($id)['is_active'])->toBe(1);

    FinanceRefusals::expect(FinanceRefusal::AlreadyActive, fn () => $handler->handle(new ReactivateAccount($id, $actor)));
});
