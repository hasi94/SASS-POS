<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\ListCustomerAddresses;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Features\ListCustomerAddresses\ListCustomerAddresses;
use Flexipos\Customers\Features\ListCustomerAddresses\ListCustomerAddressesHandler;
use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 13 - listing one customer's addresses, keyset-paged
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

function listCustomerAddresses(string $customerId, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListCustomerAddressesHandler $handler */
    $handler = app(ListCustomerAddressesHandler::class);

    return $handler->handle(new ListCustomerAddresses($customerId, $pageSize, $beforeId));
}

it('lists only the named customer, paged 3 at size 2', function () {
    $customerId = CustomersSeed::customer('Jane');
    $other = CustomersSeed::customer('Other');

    CustomersSeed::address($customerId);
    CustomersSeed::address($customerId);
    CustomersSeed::address($customerId);
    CustomersSeed::address($other);

    $page1 = listCustomerAddresses($customerId, 2);
    expect($page1->rows)->toHaveCount(2)->and($page1->nextBeforeId)->not->toBeNull();

    $page2 = listCustomerAddresses($customerId, 2, $page1->nextBeforeId);
    expect($page2->rows)->toHaveCount(1)->and($page2->nextBeforeId)->toBeNull();
});
