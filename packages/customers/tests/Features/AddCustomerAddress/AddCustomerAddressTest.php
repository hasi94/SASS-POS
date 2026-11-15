<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\AddCustomerAddress;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\AddCustomerAddress\AddCustomerAddress;
use Flexipos\Customers\Features\AddCustomerAddress\AddCustomerAddressHandler;
use Flexipos\Customers\Features\AddCustomerAddress\AddCustomerAddressResult;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 13 - adding a customer address (AC-16, AC-17)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 15:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addAddressInput(array $overrides = []): array
{
    return array_merge(['type' => 'billing'], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function addCustomerAddress(array $fields, ?string $actorId = null): AddCustomerAddressResult
{
    /** @var AddCustomerAddressHandler $handler */
    $handler = app(AddCustomerAddressHandler::class);

    return $handler->handle(new AddCustomerAddress($fields, $actorId));
}

function expectAddressRefusal(CustomersRefusal $reason, callable $operation): void
{
    CustomersRefusals::expect($reason, $operation);
}

it('adds an address, round-tripping JSON, with one audit row', function () {
    $customerId = CustomersSeed::customer('Jane');

    $result = addCustomerAddress(addAddressInput([
        'customer_id' => $customerId,
        'address' => ['line1' => '1 Main St'],
        'geo' => ['lat' => 1, 'lng' => 2],
    ]));

    $row = (array) CustomersSeed::connection()->table('customer_addresses')->where('id', $result->addressId)->first();

    expect(json_decode((string) $row['address'], true))->toBe(['line1' => '1 Main St'])
        ->and(json_decode((string) $row['geo'], true))->toBe(['lat' => 1, 'lng' => 2]);

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'customer_address_added',
        'subject_type' => 'customer_address',
        'subject_id' => $result->addressId,
        'before' => null,
    ]);
});

it('refuses an unknown or soft-deleted customer, and a bad type', function () {
    $deleted = CustomersSeed::customer('Deleted', ['deleted_at' => CustomersSeed::NOW]);

    expectAddressRefusal(CustomersRefusal::NotFound, fn () => addCustomerAddress(addAddressInput(['customer_id' => (string) Str::ulid()])));
    expectAddressRefusal(CustomersRefusal::NotFound, fn () => addCustomerAddress(addAddressInput(['customer_id' => $deleted])));
    expectAddressRefusal(CustomersRefusal::Validation, fn () => addCustomerAddress(addAddressInput(['customer_id' => CustomersSeed::customer(), 'type' => 'home'])));
});

it('lets a default billing and a default delivery coexist, but clears a second default billing (AC-17)', function () {
    $customerId = CustomersSeed::customer('Jane');

    $billing1 = addCustomerAddress(addAddressInput(['customer_id' => $customerId, 'type' => 'billing', 'is_default' => true]));
    $delivery = addCustomerAddress(addAddressInput(['customer_id' => $customerId, 'type' => 'delivery', 'is_default' => true]));
    $billing2 = addCustomerAddress(addAddressInput(['customer_id' => $customerId, 'type' => 'billing', 'is_default' => true]));

    $defaults = CustomersSeed::connection()->table('customer_addresses')->where('customer_id', $customerId)->where('is_default', true)->get();

    expect($defaults)->toHaveCount(2);

    $ids = $defaults->pluck('id')->all();
    expect($ids)->toContain($delivery->addressId)
        ->and($ids)->toContain($billing2->addressId)
        ->and($ids)->not->toContain($billing1->addressId);
});

it('leaves another customer unaffected by this customer defaulting', function () {
    $customerId = CustomersSeed::customer('Jane');
    $other = CustomersSeed::customer('Other');

    $otherDefault = addCustomerAddress(addAddressInput(['customer_id' => $other, 'type' => 'billing', 'is_default' => true]));
    addCustomerAddress(addAddressInput(['customer_id' => $customerId, 'type' => 'billing', 'is_default' => true]));

    $row = (array) CustomersSeed::connection()->table('customer_addresses')->where('id', $otherDefault->addressId)->first();
    expect((bool) $row['is_default'])->toBeTrue();
});

it('refuses a field the operation does not take', function () {
    expectAddressRefusal(CustomersRefusal::Validation, fn () => addCustomerAddress(addAddressInput(['customer_id' => CustomersSeed::customer(), 'extra' => 1])));
});
