<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\DeleteDiscount;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\CreateDiscount\CreateDiscount;
use Flexipos\Pricing\Features\CreateDiscount\CreateDiscountHandler;
use Flexipos\Pricing\Features\DeleteDiscount\DeleteDiscount;
use Flexipos\Pricing\Features\DeleteDiscount\DeleteDiscountHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 15 - soft-deleting a discount (AC-21)
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

function ddDelete(string $id): void
{
    /** @var DeleteDiscountHandler $handler */
    $handler = app(DeleteDiscountHandler::class);

    $handler->handle(new DeleteDiscount($id));
}

it('soft-deletes in place with no cascade, and writes one audit row', function () {
    $id = PricingSeed::discount('TEN', ['used_count' => 4, 'usage_limit' => 10]);
    $other = PricingSeed::discount('OTHER');

    ddDelete($id);

    $row = PricingSeed::row('discounts', $id);

    expect($row['deleted_at'])->toBe('2026-11-08 12:00:00.000')
        ->and($row['code'])->toBe('TEN')
        ->and($row['used_count'])->toBe(4)
        ->and(PricingSeed::row('discounts', $other)['deleted_at'])->toBeNull()
        ->and(PricingSeed::connection()->table('discounts')->count())->toBe(2);

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_discount_deleted', 'subject_id' => $id])
        ->and($audit['before']['deleted_at'])->toBeNull()
        ->and($audit['after']['deleted_at'])->toBe('2026-11-08 12:00:00.000');
});

it('keeps the code reserved after a delete, so a recreated code is refused as a duplicate', function () {
    $id = PricingSeed::discount('TEN');

    ddDelete($id);

    /** @var CreateDiscountHandler $create */
    $create = app(CreateDiscountHandler::class);

    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => $create->handle(new CreateDiscount(['code' => 'ten', 'name' => 'Again', 'type' => 'percentage', 'value' => '5', 'scope' => 'item'])));
});

it('refuses an unknown, a malformed and an already deleted discount', function () {
    $gone = PricingSeed::discount('GONE', ['deleted_at' => PricingSeed::NOW]);

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => ddDelete('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => ddDelete($gone));
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ddDelete('nope'));

    expect(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});
