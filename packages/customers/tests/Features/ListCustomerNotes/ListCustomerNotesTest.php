<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\ListCustomerNotes;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Features\ListCustomerNotes\ListCustomerNotes;
use Flexipos\Customers\Features\ListCustomerNotes\ListCustomerNotesHandler;
use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 14 - listing one customer's notes, keyset-paged (AC-37)
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

function listCustomerNotes(string $customerId, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListCustomerNotesHandler $handler */
    $handler = app(ListCustomerNotesHandler::class);

    return $handler->handle(new ListCustomerNotes($customerId, $pageSize, $beforeId));
}

it('lists only the named customer, paged 3 at size 2, with the malformed cursor refused', function () {
    $customerId = CustomersSeed::customer('Jane');
    $other = CustomersSeed::customer('Other');

    CustomersSeed::note($customerId);
    CustomersSeed::note($customerId);
    CustomersSeed::note($customerId);
    CustomersSeed::note($other);

    $page1 = listCustomerNotes($customerId, 2);
    expect($page1->rows)->toHaveCount(2)->and($page1->nextBeforeId)->not->toBeNull();

    $page2 = listCustomerNotes($customerId, 2, $page1->nextBeforeId);
    expect($page2->rows)->toHaveCount(1)->and($page2->nextBeforeId)->toBeNull();

    expect(fn () => listCustomerNotes($customerId, 2, 'not-a-ulid'))->toThrow(CustomersRefused::class);
});
