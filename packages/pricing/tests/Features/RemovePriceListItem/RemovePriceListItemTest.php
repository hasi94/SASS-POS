<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\RemovePriceListItem;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\RemovePriceListItem\RemovePriceListItem;
use Flexipos\Pricing\Features\RemovePriceListItem\RemovePriceListItemHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 14 - removing a price list item (AC-13)
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

function rpliRemove(string $id): void
{
    /** @var RemovePriceListItemHandler $handler */
    $handler = app(RemovePriceListItemHandler::class);

    $handler->handle(new RemovePriceListItem($id));
}

it('hard-deletes the item and writes one audit row holding the removed row', function () {
    $list = PricingSeed::priceList('L');
    $product = PricingSeed::product('P1');
    $item = PricingSeed::item($list, $product, null, '1.0000', '5.0000');
    $kept = PricingSeed::item($list, $product, null, '2.0000', '4.0000');

    rpliRemove($item);

    expect(PricingSeed::connection()->table('price_list_items')->pluck('id')->all())->toBe([$kept]);

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_price_list_item_removed', 'subject_id' => $item])
        ->and($audit['before']['id'])->toBe($item)
        ->and($audit['after'])->toBeNull();
});

it('refuses an unknown item and a malformed id', function () {
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => rpliRemove('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => rpliRemove('nope'));

    expect(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});
