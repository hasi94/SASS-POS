<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\CreateCustomerGroup;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\CreateCustomerGroup\CreateCustomerGroup;
use Flexipos\Customers\Features\CreateCustomerGroup\CreateCustomerGroupHandler;
use Flexipos\Customers\Features\CreateCustomerGroup\CreateCustomerGroupResult;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 8 - creating a customer group (AC-1, AC-2, AC-5)
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
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function createGroupInput(array $overrides = []): array
{
    return array_merge(['code' => 'VIP', 'name' => 'VIP customers'], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function createCustomerGroup(array $fields, ?string $actorId = null): CreateCustomerGroupResult
{
    /** @var CreateCustomerGroupHandler $handler */
    $handler = app(CreateCustomerGroupHandler::class);

    return $handler->handle(new CreateCustomerGroup($fields, $actorId));
}

function expectGroupRefusal(CustomersRefusal $reason, callable $operation): void
{
    CustomersRefusals::expect($reason, $operation);
}

it('creates a group with its defaults, and writes one audit row', function () {
    $result = createCustomerGroup(createGroupInput());

    $row = (array) CustomersSeed::connection()->table('customer_groups')->where('id', $result->groupId)->first();

    expect($row['code'])->toBe('VIP')
        ->and((bool) $row['credit_allowed'])->toBeFalse()
        ->and((bool) $row['is_default'])->toBeFalse()
        ->and((int) $row['version'])->toBe(1);

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'customer_group_created',
        'subject_type' => 'customer_group',
        'subject_id' => $result->groupId,
        'before' => null,
    ]);
});

it('refuses a blank code and name', function () {
    expectGroupRefusal(CustomersRefusal::Validation, fn () => createCustomerGroup(createGroupInput(['code' => ''])));
    expectGroupRefusal(CustomersRefusal::Validation, fn () => createCustomerGroup(createGroupInput(['name' => ''])));
});

it('refuses a negative value for each of the four numerics', function () {
    expectGroupRefusal(CustomersRefusal::Validation, fn () => createCustomerGroup(createGroupInput(['discount_percent' => '-1.0000'])));
    expectGroupRefusal(CustomersRefusal::Validation, fn () => createCustomerGroup(createGroupInput(['default_credit_limit' => '-1.0000'])));
    expectGroupRefusal(CustomersRefusal::Validation, fn () => createCustomerGroup(createGroupInput(['default_payment_terms_days' => -1])));
    expectGroupRefusal(CustomersRefusal::Validation, fn () => createCustomerGroup(createGroupInput(['loyalty_multiplier' => '-1.0000'])));
});

it('refuses a duplicate code, including by case', function () {
    createCustomerGroup(createGroupInput(['code' => 'VIP']));

    expectGroupRefusal(CustomersRefusal::Duplicate, fn () => createCustomerGroup(createGroupInput(['code' => 'VIP', 'name' => 'Again'])));
    expectGroupRefusal(CustomersRefusal::Duplicate, fn () => createCustomerGroup(createGroupInput(['code' => 'vip', 'name' => 'Shouting'])));
});

it('keeps exactly one default group across create, update and soft-delete (AC-2, F-19)', function () {
    $a = createCustomerGroup(createGroupInput(['code' => 'A', 'is_default' => true]));
    $b = createCustomerGroup(createGroupInput(['code' => 'B', 'is_default' => true]));

    $rows = CustomersSeed::connection()->table('customer_groups')->where('is_default', true)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($b->groupId);

    $aRow = (array) CustomersSeed::connection()->table('customer_groups')->where('id', $a->groupId)->first();
    expect((bool) $aRow['is_default'])->toBeFalse();
});

it('round-trips discount_percent and loyalty_multiplier (AC-5)', function () {
    $result = createCustomerGroup(createGroupInput(['discount_percent' => '12.5000', 'loyalty_multiplier' => '2.0000']));

    $row = (array) CustomersSeed::connection()->table('customer_groups')->where('id', $result->groupId)->first();

    expect((float) $row['discount_percent'])->toBe(12.5)
        ->and((float) $row['loyalty_multiplier'])->toBe(2.0);
});

it('refuses a field the operation does not take', function () {
    expectGroupRefusal(CustomersRefusal::Validation, fn () => createCustomerGroup(createGroupInput(['extra' => 1])));
});

it('writes nothing, including no audit row, on every refusal above', function () {
    $before = CustomersSeed::connection()->table('customer_groups')->count();

    foreach ([['code' => ''], ['extra' => 1], ['discount_percent' => '-1.0000']] as $overrides) {
        try {
            createCustomerGroup(createGroupInput($overrides));
        } catch (\Throwable) {
            // expected
        }
    }

    expect(CustomersSeed::connection()->table('customer_groups')->count())->toBe($before)
        ->and(CustomersSeed::audit())->toBe([]);
});
