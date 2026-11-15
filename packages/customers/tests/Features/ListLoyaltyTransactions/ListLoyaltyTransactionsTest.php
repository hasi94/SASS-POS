<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\ListLoyaltyTransactions;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Features\ListLoyaltyTransactions\ListLoyaltyTransactions;
use Flexipos\Customers\Features\ListLoyaltyTransactions\ListLoyaltyTransactionsHandler;
use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 12 - listing one customer's loyalty transactions,
| keyset-paged (AC-33 by absence of sale_id, pagination)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function listLoyaltyTransactions(string $customerId, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListLoyaltyTransactionsHandler $handler */
    $handler = app(ListLoyaltyTransactionsHandler::class);

    return $handler->handle(new ListLoyaltyTransactions($customerId, $pageSize, $beforeId));
}

it('lists only the named customer, paged 3 at size 2', function () {
    $customerId = CustomersSeed::customer('Jane');
    $other = CustomersSeed::customer('Other');
    $programId = CustomersSeed::program();

    CustomersSeed::loyaltyTransaction($customerId, $programId);
    CustomersSeed::loyaltyTransaction($customerId, $programId);
    CustomersSeed::loyaltyTransaction($customerId, $programId);
    CustomersSeed::loyaltyTransaction($other, $programId);

    $page1 = listLoyaltyTransactions($customerId, 2);
    expect($page1->rows)->toHaveCount(2)->and($page1->nextBeforeId)->not->toBeNull();

    $page2 = listLoyaltyTransactions($customerId, 2, $page1->nextBeforeId);
    expect($page2->rows)->toHaveCount(1)->and($page2->nextBeforeId)->toBeNull();
});
