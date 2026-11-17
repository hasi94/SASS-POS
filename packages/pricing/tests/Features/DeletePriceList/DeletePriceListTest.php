<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\DeletePriceList;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\DeletePriceList\DeletePriceList;
use Flexipos\Pricing\Features\DeletePriceList\DeletePriceListHandler;
use Flexipos\Pricing\Features\UnassignPriceList\UnassignPriceList;
use Flexipos\Pricing\Features\UnassignPriceList\UnassignPriceListHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 14 - soft-deleting a price list (AC-18)
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

function dplDelete(string $id): void
{
    /** @var DeletePriceListHandler $handler */
    $handler = app(DeletePriceListHandler::class);

    $handler->handle(new DeletePriceList($id));
}

it('soft-deletes an unassigned list and writes one audit row', function () {
    $id = PricingSeed::priceList('A');

    dplDelete($id);

    expect(PricingSeed::row('price_lists', $id)['deleted_at'])->toBe('2026-11-08 12:00:00.000');

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_price_list_deleted', 'subject_id' => $id])
        ->and($audit['before']['deleted_at'])->toBeNull()
        ->and($audit['after']['deleted_at'])->toBe('2026-11-08 12:00:00.000');
});

it('refuses a list with assignments, naming the count, and deletes it once they are removed, leaving its items', function () {
    $list = PricingSeed::priceList('A');
    $product = PricingSeed::product('P1');
    PricingSeed::item($list, $product, null, '1.0000', '5.0000');
    $a1 = PricingSeed::assignment($list, 'branch', PricingSeed::branch());
    $a2 = PricingSeed::assignment($list, 'customer_group', PricingSeed::customerGroup('G'));

    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::InUse, fn () => dplDelete($list));

    expect($refused->getMessage())->toContain('2 assignment(s)')
        ->and(PricingSeed::row('price_lists', $list)['deleted_at'])->toBeNull()
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);

    /** @var UnassignPriceListHandler $unassign */
    $unassign = app(UnassignPriceListHandler::class);
    $unassign->handle(new UnassignPriceList($a1));
    $unassign->handle(new UnassignPriceList($a2));

    // The two unassignments wrote their own audit rows; the list is still there.
    expect(PricingSeed::row('price_lists', $list)['deleted_at'])->toBeNull();

    dplDelete($list);

    expect(PricingSeed::row('price_lists', $list)['deleted_at'])->not->toBeNull()
        ->and(PricingSeed::connection()->table('price_list_items')->where('price_list_id', $list)->count())->toBe(1);
});

it('refuses an unknown list and an already deleted list', function () {
    $id = PricingSeed::priceList('A', ['deleted_at' => PricingSeed::NOW]);

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => dplDelete('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => dplDelete($id));
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => dplDelete('nope'));
});
