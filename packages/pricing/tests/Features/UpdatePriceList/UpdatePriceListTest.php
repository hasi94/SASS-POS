<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\UpdatePriceList;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\UpdatePriceList\UpdatePriceList;
use Flexipos\Pricing\Features\UpdatePriceList\UpdatePriceListHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 14 - updating a price list (AC-12)
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
function uplUpdate(string $id, array $fields): void
{
    /** @var UpdatePriceListHandler $handler */
    $handler = app(UpdatePriceListHandler::class);

    $handler->handle(new UpdatePriceList($id, $fields));
}

it('updates the named fields and writes one audit row with the row before and after', function () {
    $id = PricingSeed::priceList('RETAIL');

    uplUpdate($id, ['name' => 'Retail 2026', 'priority' => 3, 'is_active' => false]);

    expect(PricingSeed::row('price_lists', $id))->toMatchArray(['name' => 'Retail 2026', 'priority' => 3, 'is_active' => 0, 'code' => 'RETAIL']);

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_price_list_updated', 'subject_id' => $id])
        ->and($audit['before']['priority'])->toBe(0)
        ->and($audit['after']['priority'])->toBe(3);
});

it('checks the window against the row as it would be after the update', function () {
    $id = PricingSeed::priceList('W', ['starts_at' => '2025-06-15 09:30:00.000', 'ends_at' => '2025-06-30 09:30:00.000']);

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => uplUpdate($id, ['ends_at' => '2025-06-01T00:00:00.000Z']), 'moving only the end before the stored start');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => uplUpdate($id, ['starts_at' => '2025-07-01T00:00:00.000Z']), 'moving only the start after the stored end');

    uplUpdate($id, ['ends_at' => null]);
    uplUpdate($id, ['starts_at' => '2030-01-01T00:00:00.000Z']);

    expect(PricingSeed::row('price_lists', $id))->toMatchArray(['starts_at' => '2030-01-01 00:00:00.000', 'ends_at' => null]);
});

it('refuses an unknown or soft-deleted list, an empty update, a null required field and a taken code', function () {
    $id = PricingSeed::priceList('A');
    PricingSeed::priceList('B');
    $gone = PricingSeed::priceList('GONE', ['deleted_at' => PricingSeed::NOW]);

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => uplUpdate('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['name' => 'x']), 'unknown');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => uplUpdate($gone, ['name' => 'x']), 'soft-deleted');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => uplUpdate($id, []), 'empty');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => uplUpdate($id, ['currency' => null]), 'null currency');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => uplUpdate($id, ['currency' => 'usd']), 'lower-case currency');
    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => uplUpdate($id, ['code' => 'b']), 'code by case');

    expect(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});
