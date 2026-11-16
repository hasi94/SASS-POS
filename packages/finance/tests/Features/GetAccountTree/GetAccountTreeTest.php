<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\GetAccountTree;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Features\GetAccountTree\GetAccountTree;
use Flexipos\Finance\Features\GetAccountTree\GetAccountTreeHandler;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 12 - the whole-chart read (AC-61)
|--------------------------------------------------------------------------
|
| The constant-query-count proof is in `ListAccounts/ListAccountsTest.php`;
| this folder mirrors the slice (the vertical-slice layout rule).
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

it('omits soft-deleted accounts and orders by depth, then code', function () {
    $actor = FinanceSeed::actor('viewer', 'finance.accounts.view');
    $z = FinanceSeed::header('Z1');
    FinanceSeed::account('A9', ['parent_id' => $z]);
    FinanceSeed::account('B1');
    FinanceSeed::account('C1', ['deleted_at' => FinanceSeed::NOW]);

    $codes = array_column(app(GetAccountTreeHandler::class)->handle(new GetAccountTree($actor)), 'code');

    expect($codes)->toBe(['B1', 'Z1', 'A9']);
});
