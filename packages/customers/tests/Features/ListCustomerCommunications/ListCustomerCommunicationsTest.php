<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\ListCustomerCommunications;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Features\ListCustomerCommunications\ListCustomerCommunications;
use Flexipos\Customers\Features\ListCustomerCommunications\ListCustomerCommunicationsHandler;
use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 14 - listing one customer's communications, keyset-paged
| (AC-37)
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

function listCustomerCommunications(string $customerId, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListCustomerCommunicationsHandler $handler */
    $handler = app(ListCustomerCommunicationsHandler::class);

    return $handler->handle(new ListCustomerCommunications($customerId, $pageSize, $beforeId));
}

it('lists only the named customer, paged 3 at size 2', function () {
    $customerId = CustomersSeed::customer('Jane');
    $other = CustomersSeed::customer('Other');

    CustomersSeed::communication($customerId);
    CustomersSeed::communication($customerId);
    CustomersSeed::communication($customerId);
    CustomersSeed::communication($other);

    $page1 = listCustomerCommunications($customerId, 2);
    expect($page1->rows)->toHaveCount(2)->and($page1->nextBeforeId)->not->toBeNull();

    $page2 = listCustomerCommunications($customerId, 2, $page1->nextBeforeId);
    expect($page2->rows)->toHaveCount(1)->and($page2->nextBeforeId)->toBeNull();
});
