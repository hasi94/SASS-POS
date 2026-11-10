<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\UpdateCustomerGroup;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\UpdateCustomerGroup\UpdateCustomerGroup;
use Flexipos\Customers\Features\UpdateCustomerGroup\UpdateCustomerGroupHandler;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 8 - updating a customer group (AC-1, AC-2, AC-4)
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
function updateCustomerGroup(string $groupId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateCustomerGroupHandler $handler */
    $handler = app(UpdateCustomerGroupHandler::class);

    $handler->handle(new UpdateCustomerGroup($groupId, $fields, $actorId));
}

it('updates a group and increments its version, with one audit row', function () {
    $groupId = CustomersSeed::group('A');

    updateCustomerGroup($groupId, ['version' => 1, 'name' => 'Renamed']);

    $row = (array) CustomersSeed::connection()->table('customer_groups')->where('id', $groupId)->first();

    expect($row['name'])->toBe('Renamed')
        ->and((int) $row['version'])->toBe(2);

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'action' => 'customer_group_updated',
        'subject_id' => $groupId,
    ]);
});

it('refuses a missing version, and a stale one leaves the row unchanged', function () {
    $groupId = CustomersSeed::group('A');

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => updateCustomerGroup($groupId, ['name' => 'X']));
    CustomersRefusals::expect(CustomersRefusal::Stale, fn () => updateCustomerGroup($groupId, ['version' => 99, 'name' => 'X']));

    $row = (array) CustomersSeed::connection()->table('customer_groups')->where('id', $groupId)->first();
    expect($row['name'])->toBe('Group A')
        ->and((int) $row['version'])->toBe(1);
});

it('clears the old default when a group is updated to is_default (AC-2)', function () {
    $a = CustomersSeed::group('A', ['is_default' => true]);
    $b = CustomersSeed::group('B');

    updateCustomerGroup($b, ['version' => 1, 'is_default' => true]);

    $rows = CustomersSeed::connection()->table('customer_groups')->where('is_default', true)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->id)->toBe($b);
});

it('refuses a duplicate code, including by case', function () {
    CustomersSeed::group('A');
    $b = CustomersSeed::group('B');

    CustomersRefusals::expect(CustomersRefusal::Duplicate, fn () => updateCustomerGroup($b, ['version' => 1, 'code' => 'a']));
});

it('refuses an unknown group', function () {
    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => updateCustomerGroup((string) Str::ulid(), ['version' => 1, 'name' => 'X']));
});
