<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\UpdateCustomer;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\UpdateCustomer\UpdateCustomer;
use Flexipos\Customers\Features\UpdateCustomer\UpdateCustomerHandler;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 9 - updating a customer (AC-4, AC-6a, AC-8, AC-9, AC-12)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 10:00:00.000'));
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
function updateCustomer(string $customerId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateCustomerHandler $handler */
    $handler = app(UpdateCustomerHandler::class);

    $handler->handle(new UpdateCustomer($customerId, $fields, $actorId));
}

it('updates a customer and increments its version, with one audit row', function () {
    $customerId = CustomersSeed::customer();

    updateCustomer($customerId, ['version' => 1, 'name' => 'Renamed']);

    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect($row['name'])->toBe('Renamed')->and((int) $row['version'])->toBe(2);

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'action' => 'customer_updated',
        'subject_id' => $customerId,
    ]);
});

it('refuses a missing version, and a stale one leaves the row byte-identical', function () {
    $customerId = CustomersSeed::customer();

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => updateCustomer($customerId, ['name' => 'X']));

    $before = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    CustomersRefusals::expect(CustomersRefusal::Stale, fn () => updateCustomer($customerId, ['version' => 99, 'name' => 'X']));
    $after = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();

    expect($after)->toBe($before);
});

it('refuses each maintained column whole, leaving a name sent in the same request untouched (AC-8)', function () {
    $customerId = CustomersSeed::customer();
    $before = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();

    foreach (['credit_balance' => '5.0000', 'loyalty_points' => '5.0000', 'loyalty_tier' => 'gold', 'merged_into_id' => (string) Str::ulid(), 'opening_balance' => '1.0000'] as $field => $value) {
        CustomersRefusals::expect(CustomersRefusal::Maintained, fn () => updateCustomer($customerId, ['version' => 1, 'name' => 'Should not apply', $field => $value]), $field);
    }

    $after = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect($after)->toBe($before)
        ->and(CustomersSeed::audit())->toBe([]);
});

it('refuses blocked without a reason, active with a reason, and a patch clearing status without the reason (AC-9)', function () {
    $customerId = CustomersSeed::customer();

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => updateCustomer($customerId, ['version' => 1, 'status' => 'blocked']));

    updateCustomer($customerId, ['version' => 1, 'status' => 'blocked', 'blocked_reason' => 'because']);

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => updateCustomer($customerId, ['version' => 2, 'status' => 'active']));

    updateCustomer($customerId, ['version' => 2, 'status' => 'active', 'blocked_reason' => null]);
    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect($row['status'])->toBe('active')->and($row['blocked_reason'])->toBeNull();
});

it('refuses a currency change once a ledger row exists, and accepts it before one (AC-6a)', function () {
    $customerId = CustomersSeed::customer('Jane', ['currency' => 'USD']);

    updateCustomer($customerId, ['version' => 1, 'currency' => 'LKR']);
    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect($row['currency'])->toBe('LKR');

    CustomersSeed::ledgerEntry($customerId, ['currency' => 'LKR']);

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => updateCustomer($customerId, ['version' => 2, 'currency' => 'USD']));
});

it('sets possible_duplicate_of, refuses pointing at itself, and refuses an unknown id (AC-12)', function () {
    $customerId = CustomersSeed::customer('Jane');
    $other = CustomersSeed::customer('Other');

    updateCustomer($customerId, ['version' => 1, 'possible_duplicate_of' => $other]);
    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect($row['possible_duplicate_of'])->toBe($other);

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => updateCustomer($customerId, ['version' => 2, 'possible_duplicate_of' => $customerId]));
    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => updateCustomer($customerId, ['version' => 2, 'possible_duplicate_of' => (string) Str::ulid()]));
});

it('refuses an unknown customer', function () {
    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => updateCustomer((string) Str::ulid(), ['version' => 1, 'name' => 'X']));
});
