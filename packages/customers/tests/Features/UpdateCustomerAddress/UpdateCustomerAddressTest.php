<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\UpdateCustomerAddress;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\UpdateCustomerAddress\UpdateCustomerAddress;
use Flexipos\Customers\Features\UpdateCustomerAddress\UpdateCustomerAddressHandler;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 13 - updating a customer address (AC-16, AC-17)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 15:30:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $fields
 */
function updateCustomerAddress(string $addressId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateCustomerAddressHandler $handler */
    $handler = app(UpdateCustomerAddressHandler::class);

    $handler->handle(new UpdateCustomerAddress($addressId, $fields, $actorId));
}

it('updates a label and address, with one audit row', function () {
    $customerId = CustomersSeed::customer('Jane');
    $addressId = CustomersSeed::address($customerId, ['label' => 'Old']);

    updateCustomerAddress($addressId, ['label' => 'New']);

    $row = (array) CustomersSeed::connection()->table('customer_addresses')->where('id', $addressId)->first();
    expect($row['label'])->toBe('New');

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'customer_address_updated',
        'subject_type' => 'customer_address',
        'subject_id' => $addressId,
    ]);
});

it('refuses an unknown id', function () {
    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => updateCustomerAddress((string) Str::ulid(), ['label' => 'New']));
});

it('clears the previous default of the same type by add and by update (AC-17)', function () {
    $customerId = CustomersSeed::customer('Jane');
    $first = CustomersSeed::address($customerId, ['type' => 'billing', 'is_default' => true]);
    $second = CustomersSeed::address($customerId, ['type' => 'billing', 'is_default' => false]);

    updateCustomerAddress($second, ['is_default' => true]);

    $firstRow = (array) CustomersSeed::connection()->table('customer_addresses')->where('id', $first)->first();
    $secondRow = (array) CustomersSeed::connection()->table('customer_addresses')->where('id', $second)->first();

    expect((bool) $firstRow['is_default'])->toBeFalse()
        ->and((bool) $secondRow['is_default'])->toBeTrue();
});

it('refuses a field the operation does not take', function () {
    $customerId = CustomersSeed::customer('Jane');
    $addressId = CustomersSeed::address($customerId);

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => updateCustomerAddress($addressId, ['customer_id' => (string) Str::ulid()]));
});
