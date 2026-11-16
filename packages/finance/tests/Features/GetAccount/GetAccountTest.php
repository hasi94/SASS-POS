<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\GetAccount;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\GetAccount\GetAccount;
use Flexipos\Finance\Features\GetAccount\GetAccountHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 12 - reading one account (AC-61, AC-64)
|--------------------------------------------------------------------------
|
| The list and tree reads are in `ListAccounts/ListAccountsTest.php`; this
| folder mirrors the slice (the vertical-slice layout rule).
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

it('returns one live account, folding the id, and refuses absent or deleted ones as not found', function () {
    $actor = FinanceSeed::actor('viewer', 'finance.accounts.view');
    $id = FinanceSeed::account('A1');
    $dead = FinanceSeed::account('A2', ['deleted_at' => FinanceSeed::NOW]);

    $handler = app(GetAccountHandler::class);

    expect($handler->handle(new GetAccount(strtolower($id), $actor)))->toMatchArray(['id' => $id, 'code' => 'A1']);

    FinanceRefusals::expect(FinanceRefusal::AccountNotFound, fn () => $handler->handle(new GetAccount($dead, $actor)));
    FinanceRefusals::expect(FinanceRefusal::AccountNotFound, fn () => $handler->handle(new GetAccount((string) Str::ulid(), $actor)));
});
