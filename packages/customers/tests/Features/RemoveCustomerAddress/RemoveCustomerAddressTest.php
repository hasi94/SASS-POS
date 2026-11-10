<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\RemoveCustomerAddress;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\RemoveCustomerAddress\RemoveCustomerAddress;
use Flexipos\Customers\Features\RemoveCustomerAddress\RemoveCustomerAddressHandler;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 13 - removing a customer address (AC-18, D-16)
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

function removeCustomerAddress(string $addressId, ?string $actorId = null): void
{
    /** @var RemoveCustomerAddressHandler $handler */
    $handler = app(RemoveCustomerAddressHandler::class);

    $handler->handle(new RemoveCustomerAddress($addressId, $actorId));
}

it('removes the row for good, with one audit row', function () {
    $customerId = CustomersSeed::customer('Jane');
    $addressId = CustomersSeed::address($customerId);

    removeCustomerAddress($addressId);

    expect(CustomersSeed::connection()->table('customer_addresses')->where('id', $addressId)->count())->toBe(0);

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'customer_address_removed',
        'subject_type' => 'customer_address',
        'subject_id' => $addressId,
        'after' => null,
    ]);
});

it('refuses an unknown id, naming it', function () {
    $missing = (string) Str::ulid();

    $refused = CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => removeCustomerAddress($missing));

    expect($refused->getMessage())->toContain($missing);
});
