<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\AssignPriceList;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\AssignPriceList\AssignPriceList;
use Flexipos\Pricing\Features\AssignPriceList\AssignPriceListHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 14 - assigning a price list (AC-14, F-8)
|--------------------------------------------------------------------------
|
| `scope_id` is polymorphic and carries no foreign key, so the existence of its
| target is checked here for all three scope types.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 12:00:00.000'));
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
function aplAssign(array $fields): string
{
    /** @var AssignPriceListHandler $handler */
    $handler = app(AssignPriceListHandler::class);

    return $handler->handle(new AssignPriceList($fields))->priceListAssignmentId;
}

/**
 * @return array<string, string>
 */
function aplTargets(): array
{
    return [
        'branch' => PricingSeed::branch(),
        'customer_group' => PricingSeed::customerGroup('G'),
        'customer' => PricingSeed::customer('Ada'),
    ];
}

it('assigns a list to each of the three scope types, defaulting priority to 0, with one audit row each', function (string $scopeType) {
    $list = PricingSeed::priceList('L');
    $scopeId = aplTargets()[$scopeType];

    $id = aplAssign(['price_list_id' => $list, 'scope_type' => $scopeType, 'scope_id' => strtolower($scopeId)]);

    expect(PricingSeed::row('price_list_assignments', $id))->toMatchArray([
        'price_list_id' => $list,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'priority' => 0,
    ]);

    expect(PricingSeed::onlyAuditRow())->toMatchArray(['action' => 'pricing_price_list_assigned', 'subject_type' => 'price_list_assignment', 'subject_id' => $id]);
})->with(['branch', 'customer_group', 'customer']);

it('refuses an unknown target for each scope type, naming the type and the id', function (string $scopeType) {
    $list = PricingSeed::priceList('L');
    $missing = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aplAssign(['price_list_id' => $list, 'scope_type' => $scopeType, 'scope_id' => $missing]));

    expect($refused->getMessage())->toContain($scopeType)->toContain($missing)
        ->and(PricingSeed::connection()->table('price_list_assignments')->count())->toBe(0);
})->with(['branch', 'customer_group', 'customer']);

it('refuses a soft-deleted customer group and customer', function () {
    $list = PricingSeed::priceList('L');
    $group = PricingSeed::customerGroup('G');
    $customer = PricingSeed::customer('Ada');

    PricingSeed::connection()->table('customer_groups')->where('id', $group)->update(['deleted_at' => PricingSeed::NOW]);
    PricingSeed::connection()->table('customers')->where('id', $customer)->update(['deleted_at' => PricingSeed::NOW]);

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aplAssign(['price_list_id' => $list, 'scope_type' => 'customer_group', 'scope_id' => $group]));
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aplAssign(['price_list_id' => $list, 'scope_type' => 'customer', 'scope_id' => $customer]));
});

it('refuses a scope id that exists only in the wrong table', function () {
    $list = PricingSeed::priceList('L');
    $targets = aplTargets();

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aplAssign(['price_list_id' => $list, 'scope_type' => 'customer', 'scope_id' => $targets['branch']]));
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aplAssign(['price_list_id' => $list, 'scope_type' => 'branch', 'scope_id' => $targets['customer_group']]));
});

it('refuses a bad scope type, a missing one, a malformed scope id and an unknown or soft-deleted list', function () {
    $list = PricingSeed::priceList('L');
    $branch = PricingSeed::branch();

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aplAssign(['price_list_id' => $list, 'scope_type' => 'terminal', 'scope_id' => $branch]), 'bad type');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aplAssign(['price_list_id' => $list, 'scope_id' => $branch]), 'no type');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aplAssign(['price_list_id' => $list, 'scope_type' => 'branch', 'scope_id' => 'nope']), 'malformed scope id');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aplAssign(['price_list_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'scope_type' => 'branch', 'scope_id' => $branch]), 'unknown list');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aplAssign(['price_list_id' => PricingSeed::priceList('GONE', ['deleted_at' => PricingSeed::NOW]), 'scope_type' => 'branch', 'scope_id' => $branch]), 'soft-deleted list');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aplAssign(['price_list_id' => $list, 'scope_type' => 'branch', 'scope_id' => $branch, 'priority' => 'high']), 'bad priority');

    expect(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('refuses the same triple twice, and allows the same list at another scope or another list at the same scope', function () {
    $list = PricingSeed::priceList('L');
    $other = PricingSeed::priceList('M');
    $branch = PricingSeed::branch();

    aplAssign(['price_list_id' => $list, 'scope_type' => 'branch', 'scope_id' => $branch, 'priority' => 3]);

    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => aplAssign(['price_list_id' => $list, 'scope_type' => 'branch', 'scope_id' => $branch]));

    aplAssign(['price_list_id' => $other, 'scope_type' => 'branch', 'scope_id' => $branch]);
    aplAssign(['price_list_id' => $list, 'scope_type' => 'customer_group', 'scope_id' => PricingSeed::customerGroup('G')]);

    expect(PricingSeed::connection()->table('price_list_assignments')->count())->toBe(3);
});
