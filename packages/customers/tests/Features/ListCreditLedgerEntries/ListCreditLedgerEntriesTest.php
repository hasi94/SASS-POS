<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\ListCreditLedgerEntries;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Features\ListCreditLedgerEntries\ListCreditLedgerEntries;
use Flexipos\Customers\Features\ListCreditLedgerEntries\ListCreditLedgerEntriesHandler;
use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 10 - listing one customer's ledger entries, keyset-paged
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

function listCreditLedgerEntries(string $customerId, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListCreditLedgerEntriesHandler $handler */
    $handler = app(ListCreditLedgerEntriesHandler::class);

    return $handler->handle(new ListCreditLedgerEntries($customerId, $pageSize, $beforeId));
}

it('lists only the named customer, paged 3 at size 2', function () {
    $customerId = CustomersSeed::customer('Jane');
    $other = CustomersSeed::customer('Other');

    CustomersSeed::ledgerEntry($customerId, ['reference_id' => $customerId]);
    CustomersSeed::ledgerEntry($customerId, ['reference_id' => $customerId, 'type' => 'payment']);
    CustomersSeed::ledgerEntry($customerId, ['reference_id' => $customerId, 'type' => 'refund']);
    CustomersSeed::ledgerEntry($other, ['reference_id' => $other]);

    $page1 = listCreditLedgerEntries($customerId, 2);
    expect($page1->rows)->toHaveCount(2)->and($page1->nextBeforeId)->not->toBeNull();

    $page2 = listCreditLedgerEntries($customerId, 2, $page1->nextBeforeId);
    expect($page2->rows)->toHaveCount(1)->and($page2->nextBeforeId)->toBeNull();
});
