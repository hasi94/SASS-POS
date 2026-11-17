<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\UnassignPriceList;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\UnassignPriceList\UnassignPriceList;
use Flexipos\Pricing\Features\UnassignPriceList\UnassignPriceListHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 14 - removing a price list assignment (AC-14, AC-18)
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

function upaRemove(string $id): void
{
    /** @var UnassignPriceListHandler $handler */
    $handler = app(UnassignPriceListHandler::class);

    $handler->handle(new UnassignPriceList($id));
}

it('hard-deletes the assignment, leaves the list in place and writes one audit row', function () {
    $list = PricingSeed::priceList('L');
    $assignment = PricingSeed::assignment($list, 'branch', PricingSeed::branch(), 4);

    upaRemove($assignment);

    expect(PricingSeed::connection()->table('price_list_assignments')->count())->toBe(0)
        ->and(PricingSeed::row('price_lists', $list)['deleted_at'])->toBeNull();

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_price_list_unassigned', 'subject_id' => $assignment])
        ->and($audit['before']['priority'])->toBe(4)
        ->and($audit['after'])->toBeNull();
});

it('refuses an unknown assignment and a malformed id', function () {
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => upaRemove('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => upaRemove('nope'));

    expect(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});
