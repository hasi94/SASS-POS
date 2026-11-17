<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\UpdatePriceListItem;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\UpdatePriceListItem\UpdatePriceListItem;
use Flexipos\Pricing\Features\UpdatePriceListItem\UpdatePriceListItemHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 14 - changing a price list item (AC-13)
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
function upliUpdate(string $id, array $fields): void
{
    /** @var UpdatePriceListItemHandler $handler */
    $handler = app(UpdatePriceListItemHandler::class);

    $handler->handle(new UpdatePriceListItem($id, $fields));
}

function upliItem(): string
{
    return PricingSeed::item(PricingSeed::priceList('L'), PricingSeed::product('P1'), null, '1.0000', '5.0000');
}

it('changes the price and the discount percent and writes one audit row with the row before and after', function () {
    $item = upliItem();

    upliUpdate($item, ['price' => '6.25', 'discount_percent' => '10']);

    expect((float) PricingSeed::row('price_list_items', $item)['price'])->toBe(6.25)
        ->and((float) PricingSeed::row('price_list_items', $item)['discount_percent'])->toBe(10.0);

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_price_list_item_updated', 'subject_id' => $item])
        ->and((float) $audit['before']['price'])->toBe(5.0)
        ->and((float) $audit['after']['price'])->toBe(6.25);
});

it('clears the discount percent with null', function () {
    $item = upliItem();

    upliUpdate($item, ['discount_percent' => '10']);
    upliUpdate($item, ['discount_percent' => null]);

    expect(PricingSeed::row('price_list_items', $item)['discount_percent'])->toBeNull();
});

it('does not take the identity columns as update fields', function (string $field, string $value) {
    $item = upliItem();

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => upliUpdate($item, [$field => $value]));

    expect(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
})->with([
    'min_qty' => ['min_qty', '2.0000'],
    'unit_id' => ['unit_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'product_id' => ['product_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'price_list_id' => ['price_list_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
]);

it('refuses an unknown item, an empty update, a null or negative price and an out-of-range percent', function () {
    $item = upliItem();

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => upliUpdate('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['price' => '1']), 'unknown');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => upliUpdate($item, []), 'empty');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => upliUpdate($item, ['price' => null]), 'null price');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => upliUpdate($item, ['price' => '-1']), 'negative price');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => upliUpdate($item, ['discount_percent' => '100.0001']), 'over 100');

    expect((float) PricingSeed::row('price_list_items', $item)['price'])->toBe(5.0)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});
