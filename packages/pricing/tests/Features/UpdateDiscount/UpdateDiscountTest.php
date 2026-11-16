<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\UpdateDiscount;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\UpdateDiscount\UpdateDiscount;
use Flexipos\Pricing\Features\UpdateDiscount\UpdateDiscountHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 15 - updating a discount (AC-19)
|--------------------------------------------------------------------------
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
function udUpdate(string $id, array $fields): void
{
    /** @var UpdateDiscountHandler $handler */
    $handler = app(UpdateDiscountHandler::class);

    $handler->handle(new UpdateDiscount($id, $fields));
}

it('updates the named fields and writes one audit row with the row before and after', function () {
    $id = PricingSeed::discount('TEN');

    udUpdate($id, ['name' => 'Fifteen off', 'value' => '15', 'applies_to' => ['categories' => ['C1']], 'is_active' => false]);

    $row = PricingSeed::row('discounts', $id);

    expect($row)->toMatchArray(['name' => 'Fifteen off', 'is_active' => 0, 'code' => 'TEN'])
        ->and((float) $row['value'])->toBe(15.0)
        ->and(json_decode((string) $row['applies_to'], true))->toBe(['categories' => ['C1']]);

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_discount_updated', 'subject_id' => $id])
        ->and($audit['before']['name'])->toBe('Discount TEN')
        ->and($audit['after']['name'])->toBe('Fifteen off');
});

it('refuses used_count whole: the row is byte-identical and no audit row is written', function () {
    $id = PricingSeed::discount('TEN');
    $before = PricingSeed::row('discounts', $id);

    PricingAdminRefusals::expect(PricingAdminRefusal::Maintained, fn () => udUpdate($id, ['used_count' => 5, 'name' => 'Changed in the same request']));

    expect(PricingSeed::row('discounts', $id))->toBe($before)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('checks the cross-field rules against the row as it would be after the update', function () {
    $fixed = PricingSeed::discount('FIX', ['type' => 'fixed', 'value' => '500.0000']);
    $windowed = PricingSeed::discount('WIN', ['starts_at' => '2025-06-15 09:30:00.000', 'ends_at' => '2025-06-30 09:30:00.000']);
    $percent = PricingSeed::discount('PCT');

    // Moving only the type to percentage leaves the stored 500 above 100.
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => udUpdate($fixed, ['type' => 'percentage']), 'type only');
    // Moving only the value above 100 on a stored percentage.
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => udUpdate($percent, ['value' => '100.0001']), 'value only');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => udUpdate($windowed, ['ends_at' => '2025-06-01T00:00:00.000Z']), 'end before the stored start');
    PricingAdminRefusals::expect(PricingAdminRefusal::MissingTarget, fn () => udUpdate($percent, ['type' => 'buy_x_get_y']), 'buy_x_get_y with no stored products');

    udUpdate($percent, ['value' => '100']);
    udUpdate($fixed, ['type' => 'percentage', 'value' => '50']);
    udUpdate($percent, ['type' => 'buy_x_get_y', 'applies_to' => ['products' => ['P1']]]);

    expect(PricingSeed::connection()->table('audit_logs')->count())->toBe(3);
});

it('refuses an unknown or soft-deleted discount, an empty update, a null required field, an unknown field and a taken code', function () {
    $id = PricingSeed::discount('A');
    PricingSeed::discount('B');
    $gone = PricingSeed::discount('GONE', ['deleted_at' => PricingSeed::NOW]);

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => udUpdate('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['name' => 'x']), 'unknown');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => udUpdate($gone, ['name' => 'x']), 'soft-deleted');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => udUpdate($id, []), 'empty');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => udUpdate($id, ['value' => null]), 'null value');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => udUpdate($id, ['sales_discount_account_id' => 'x']), 'account column');
    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => udUpdate($id, ['code' => 'b']), 'code by case');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => udUpdate($id, ['workflow_definition_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']), 'unknown workflow');

    expect(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('lets the optional columns be cleared with null', function () {
    $id = PricingSeed::discount('A', ['min_amount' => '10.0000', 'max_discount' => '5.0000', 'usage_limit' => 3, 'applies_to' => '{"products":["P"]}']);

    udUpdate($id, ['min_amount' => null, 'max_discount' => null, 'usage_limit' => null, 'applies_to' => null]);

    expect(PricingSeed::row('discounts', $id))->toMatchArray(['min_amount' => null, 'max_discount' => null, 'usage_limit' => null, 'applies_to' => null]);
});
