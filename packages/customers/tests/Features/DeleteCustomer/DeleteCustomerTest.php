<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\DeleteCustomer;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\DeleteCustomer\DeleteCustomer;
use Flexipos\Customers\Features\DeleteCustomer\DeleteCustomerHandler;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 9 - soft-deleting a customer (AC-10, D-9)
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

function deleteCustomer(string $customerId, ?string $actorId = null): void
{
    /** @var DeleteCustomerHandler $handler */
    $handler = app(DeleteCustomerHandler::class);

    $handler->handle(new DeleteCustomer($customerId, $actorId));
}

it('refuses a delete while credit_balance is not zero, naming it', function () {
    $customerId = CustomersSeed::customer('Jane', ['credit_balance' => '0.0001']);

    $refused = CustomersRefusals::capture(fn () => deleteCustomer($customerId));
    expect($refused->reason)->toBe(CustomersRefusal::BalanceOutstanding);
});

it('accepts the delete at a zero balance, even with history rows seeded', function () {
    $customerId = CustomersSeed::customer('Jane', ['credit_balance' => '0.0000']);
    CustomersSeed::ledgerEntry($customerId);

    deleteCustomer($customerId);

    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect($row['deleted_at'])->not->toBeNull();

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'action' => 'customer_deleted',
        'subject_id' => $customerId,
    ]);
});

it('refuses an unknown or already-deleted customer', function () {
    $customerId = CustomersSeed::customer();
    deleteCustomer($customerId);

    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => deleteCustomer($customerId));
});
