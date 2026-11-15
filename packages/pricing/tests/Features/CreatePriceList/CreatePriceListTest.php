<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\CreatePriceList;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\CreatePriceList\CreatePriceList;
use Flexipos\Pricing\Features\CreatePriceList\CreatePriceListHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 14 - creating a price list (AC-12)
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
function cplCreate(array $fields): string
{
    /** @var CreatePriceListHandler $handler */
    $handler = app(CreatePriceListHandler::class);

    return $handler->handle(new CreatePriceList($fields))->priceListId;
}

it('creates a list with the documented defaults and writes one audit row', function () {
    $id = cplCreate(['code' => 'RETAIL', 'name' => 'Retail', 'type' => 'standard', 'currency' => 'USD']);

    expect(PricingSeed::row('price_lists', $id))->toMatchArray([
        'code' => 'RETAIL',
        'type' => 'standard',
        'currency' => 'USD',
        'priority' => 0,
        'starts_at' => null,
        'ends_at' => null,
        'is_active' => 1,
        'deleted_at' => null,
    ]);

    expect(PricingSeed::onlyAuditRow())->toMatchArray(['action' => 'pricing_price_list_created', 'subject_type' => 'price_list', 'subject_id' => $id]);
});

it('stores a window given as engine instants as the DATETIME(3) strings the tenant database holds', function () {
    $id = cplCreate([
        'code' => 'PROMO', 'name' => 'Promo', 'type' => 'promotion', 'currency' => 'USD', 'priority' => 5,
        'starts_at' => '2025-06-15T09:30:00.000Z', 'ends_at' => '2025-06-30T23:59:59.999Z',
    ]);

    expect(PricingSeed::row('price_lists', $id))->toMatchArray([
        'starts_at' => '2025-06-15 09:30:00.000',
        'ends_at' => '2025-06-30 23:59:59.999',
        'priority' => 5,
    ]);
});

it('requires a currency and never defaults one (F-7), and accepts only three upper-case letters', function () {
    $base = ['code' => 'L', 'name' => 'L', 'type' => 'standard'];

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate($base), 'missing');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'currency' => null]), 'null');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'currency' => 'usd']), 'lower case');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'currency' => 'US']), 'two letters');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'currency' => 'USDX']), 'four letters');

    expect(PricingSeed::connection()->table('price_lists')->count())->toBe(0);
});

it('refuses a missing or blank code or name, and a type outside the four', function () {
    $base = ['code' => 'L', 'name' => 'L', 'type' => 'standard', 'currency' => 'USD'];

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'code' => ' ']), 'blank code');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'name' => '']), 'blank name');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'type' => 'wholesale']), 'bad type');

    $withoutType = $base;
    unset($withoutType['type']);
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate($withoutType), 'no type');

    foreach (['standard', 'customer_group', 'promotion', 'branch'] as $i => $type) {
        cplCreate([...$base, 'code' => "T{$i}", 'type' => $type]);
    }

    expect(PricingSeed::connection()->table('price_lists')->count())->toBe(4);
});

it('refuses an ends_at before the starts_at, and accepts either end alone or equal ends', function () {
    $base = ['name' => 'L', 'type' => 'standard', 'currency' => 'USD'];

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'code' => 'A', 'starts_at' => '2025-06-15T09:30:00.001Z', 'ends_at' => '2025-06-15T09:30:00.000Z']));
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'code' => 'B', 'starts_at' => '2025-06-15 09:30:00']), 'not the engine instant shape');

    cplCreate([...$base, 'code' => 'C', 'starts_at' => '2025-06-15T09:30:00.000Z']);
    cplCreate([...$base, 'code' => 'D', 'ends_at' => '2025-06-15T09:30:00.000Z']);
    cplCreate([...$base, 'code' => 'E', 'starts_at' => '2025-06-15T09:30:00.000Z', 'ends_at' => '2025-06-15T09:30:00.000Z']);

    expect(PricingSeed::connection()->table('price_lists')->count())->toBe(3);
});

it('refuses a duplicate code also by case, which keeps the engine tiebreak a total order (D-29)', function () {
    $base = ['name' => 'L', 'type' => 'standard', 'currency' => 'USD'];

    cplCreate([...$base, 'code' => 'Retail']);

    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => cplCreate([...$base, 'code' => 'retail']));
    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => cplCreate([...$base, 'code' => 'RETAIL']));

    expect(PricingSeed::connection()->table('price_lists')->count())->toBe(1);
});

it('refuses a priority that is not a smallint', function () {
    $base = ['code' => 'L', 'name' => 'L', 'type' => 'standard', 'currency' => 'USD'];

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'priority' => 32768]), 'too large');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => cplCreate([...$base, 'priority' => '5']), 'a string');

    cplCreate([...$base, 'priority' => -32768]);

    expect(PricingSeed::connection()->table('price_lists')->count())->toBe(1);
});
