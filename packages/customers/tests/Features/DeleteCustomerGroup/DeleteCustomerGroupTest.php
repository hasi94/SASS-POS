<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\DeleteCustomerGroup;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\DeleteCustomerGroup\DeleteCustomerGroup;
use Flexipos\Customers\Features\DeleteCustomerGroup\DeleteCustomerGroupHandler;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 8 - soft-deleting a customer group (AC-3, F-19)
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

function deleteCustomerGroup(string $groupId, ?string $actorId = null): void
{
    /** @var DeleteCustomerGroupHandler $handler */
    $handler = app(DeleteCustomerGroupHandler::class);

    $handler->handle(new DeleteCustomerGroup($groupId, $actorId));
}

it('refuses a delete while a live customer references the group, naming it', function () {
    $groupId = CustomersSeed::group('A');
    $customerId = CustomersSeed::customer('Jane', ['group_id' => $groupId]);

    $refused = CustomersRefusals::capture(fn () => deleteCustomerGroup($groupId));
    expect($refused->reason)->toBe(CustomersRefusal::InUse)
        ->and($refused->getMessage())->toContain($customerId);
});

it('accepts the delete once that customer is soft-deleted', function () {
    $groupId = CustomersSeed::group('A');
    $customerId = CustomersSeed::customer('Jane', ['group_id' => $groupId]);

    CustomersSeed::connection()->table('customers')->where('id', $customerId)->update(['deleted_at' => CustomersSeed::NOW]);

    deleteCustomerGroup($groupId);

    $row = (array) CustomersSeed::connection()->table('customer_groups')->where('id', $groupId)->first();
    expect($row['deleted_at'])->not->toBeNull();
});

it('clears is_default when the default group is deleted (F-19)', function () {
    $groupId = CustomersSeed::group('A', ['is_default' => true]);

    deleteCustomerGroup($groupId);

    $row = (array) CustomersSeed::connection()->table('customer_groups')->where('id', $groupId)->first();
    expect((bool) $row['is_default'])->toBeFalse();
});

it('refuses an unknown or already-deleted group', function () {
    $groupId = CustomersSeed::group('A');
    deleteCustomerGroup($groupId);

    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => deleteCustomerGroup($groupId));
});
