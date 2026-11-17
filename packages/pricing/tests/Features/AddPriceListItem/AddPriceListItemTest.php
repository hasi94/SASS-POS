<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\AddPriceListItem;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Catalog\ResolvedFactor;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\AddPriceListItem\AddPriceListItem;
use Flexipos\Pricing\Features\AddPriceListItem\AddPriceListItemHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use LogicException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 14 - adding a price list item (AC-13)
|--------------------------------------------------------------------------
|
| The unit reachability check goes through the real `UnitResolver` the host
| binds: a dozen is 12 each in the global ladder (reachable), a kilogram is a
| different dimension from an each-stocked product (not reachable).
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
function aliAdd(array $fields): string
{
    /** @var AddPriceListItemHandler $handler */
    $handler = app(AddPriceListItemHandler::class);

    return $handler->handle(new AddPriceListItem($fields))->priceListItemId;
}

/**
 * @return array{list: string, product: string, each: string, dozen: string, kg: string}
 */
function aliSeed(): array
{
    $each = PricingSeed::unit('each');
    $dozen = PricingSeed::unit('dozen', 'count', ['base_unit_id' => $each, 'factor' => '12.000000']);
    $kg = PricingSeed::unit('kg', 'mass');

    return [
        'list' => PricingSeed::priceList('RETAIL'),
        'product' => PricingSeed::product('P1', $each),
        'each' => $each,
        'dozen' => $dozen,
        'kg' => $kg,
    ];
}

it('adds a stock-unit item with the defaults and writes one audit row', function () {
    $seed = aliSeed();

    $id = aliAdd(['price_list_id' => $seed['list'], 'product_id' => $seed['product'], 'price' => '7.5']);

    expect(PricingSeed::row('price_list_items', $id))->toMatchArray([
        'price_list_id' => $seed['list'],
        'product_id' => $seed['product'],
        'unit_id' => null,
        'discount_percent' => null,
    ])->and((float) PricingSeed::row('price_list_items', $id)['min_qty'])->toBe(1.0)
        ->and((float) PricingSeed::row('price_list_items', $id)['price'])->toBe(7.5);

    expect(PricingSeed::onlyAuditRow())->toMatchArray(['action' => 'pricing_price_list_item_added', 'subject_type' => 'price_list_item', 'subject_id' => $id]);
});

it('accepts a reachable unit and the stock unit itself, and refuses a cross-dimension unit as unresolvable_unit (AC-13)', function () {
    $seed = aliSeed();
    $base = ['price_list_id' => $seed['list'], 'product_id' => $seed['product'], 'price' => '1'];

    aliAdd([...$base, 'unit_id' => $seed['dozen']]);
    aliAdd([...$base, 'unit_id' => $seed['each']]);

    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::UnresolvableUnit, fn () => aliAdd([...$base, 'unit_id' => $seed['kg']]));

    expect($refused->getMessage())->toContain($seed['kg'])
        ->and(PricingSeed::connection()->table('price_list_items')->count())->toBe(2);
});

it('refuses a unit that does not exist, as unresolvable', function () {
    $seed = aliSeed();

    PricingAdminRefusals::expect(PricingAdminRefusal::UnresolvableUnit, fn () => aliAdd([
        'price_list_id' => $seed['list'], 'product_id' => $seed['product'], 'price' => '1', 'unit_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]));
});

it('asks the published UnitResolver and no other source for reachability', function () {
    $seed = aliSeed();
    $double = new class implements UnitResolver
    {
        /** @var list<array{string, string}> */
        public array $asked = [];

        public function resolve(string $productId, string $fromUnitId): ResolvedFactor
        {
            $this->asked[] = [$productId, $fromUnitId];

            throw UnitResolutionRefused::noConversionPath('The double refuses everything.');
        }

        public function snapshot(string $productId, string $unitId, int|string $quantity): UnitSnapshot
        {
            throw new LogicException('Not used.');
        }

        public function snapshotInherited(string $productId, string $referencedUnitId, string $referencedUnitFactor, string $unitId, int|string $quantity): UnitSnapshot
        {
            throw new LogicException('Not used.');
        }
    };

    app()->instance(UnitResolver::class, $double);

    // The dozen would be reachable in the real ladder; the double says no.
    PricingAdminRefusals::expect(PricingAdminRefusal::UnresolvableUnit, fn () => aliAdd([
        'price_list_id' => $seed['list'], 'product_id' => $seed['product'], 'price' => '1', 'unit_id' => $seed['dozen'],
    ]));

    expect($double->asked)->toBe([[$seed['product'], $seed['dozen']]]);
});

it('refuses an unknown or soft-deleted list or product, and malformed ids', function () {
    $seed = aliSeed();
    $deletedList = PricingSeed::priceList('GONE', ['deleted_at' => PricingSeed::NOW]);
    $deletedProduct = PricingSeed::product('GONE', null, ['deleted_at' => PricingSeed::NOW]);
    $base = ['price_list_id' => $seed['list'], 'product_id' => $seed['product'], 'price' => '1'];

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aliAdd([...$base, 'price_list_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']), 'unknown list');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aliAdd([...$base, 'price_list_id' => $deletedList]), 'soft-deleted list');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aliAdd([...$base, 'product_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']), 'unknown product');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => aliAdd([...$base, 'product_id' => $deletedProduct]), 'soft-deleted product');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aliAdd([...$base, 'product_id' => 'nope']), 'malformed product id');

    expect(PricingSeed::connection()->table('price_list_items')->count())->toBe(0);
});

it('refuses a zero or negative min_qty, a missing or negative price and an out-of-range discount_percent', function () {
    $seed = aliSeed();
    $base = ['price_list_id' => $seed['list'], 'product_id' => $seed['product'], 'price' => '1'];

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aliAdd([...$base, 'min_qty' => '0']), 'zero');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aliAdd([...$base, 'min_qty' => '0.0000']), 'zero with scale');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aliAdd([...$base, 'min_qty' => '-1']), 'negative');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aliAdd(['price_list_id' => $seed['list'], 'product_id' => $seed['product']]), 'no price');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aliAdd([...$base, 'price' => '-0.01']), 'negative price');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aliAdd([...$base, 'discount_percent' => '100.0001']), 'over 100');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => aliAdd([...$base, 'discount_percent' => '-1']), 'negative percent');

    aliAdd([...$base, 'min_qty' => '0.0001', 'discount_percent' => '100.0000']);
    aliAdd([...$base, 'min_qty' => '2', 'price' => '0', 'discount_percent' => '0']);

    expect(PricingSeed::connection()->table('price_list_items')->count())->toBe(2);
});

it('refuses a duplicate at the same list, product, unit and min_qty, naming the existing row, comparing numbers and not text', function () {
    $seed = aliSeed();
    $base = ['price_list_id' => $seed['list'], 'product_id' => $seed['product'], 'price' => '1'];

    $first = aliAdd([...$base, 'min_qty' => '1']);

    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => aliAdd([...$base, 'min_qty' => '1.0000']));

    expect($refused->getMessage())->toContain($first);

    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => aliAdd([...$base, 'min_qty' => '01']), 'a leading zero is the same number');

    // A different unit key, a different min_qty, a different product and a different list are all fine.
    aliAdd([...$base, 'min_qty' => '1', 'unit_id' => $seed['dozen']]);
    aliAdd([...$base, 'min_qty' => '2']);
    aliAdd([...$base, 'product_id' => PricingSeed::product('P2'), 'min_qty' => '1']);
    aliAdd([...$base, 'price_list_id' => PricingSeed::priceList('OTHER'), 'min_qty' => '1']);

    expect(PricingSeed::connection()->table('price_list_items')->count())->toBe(5);
});

it('refuses two unit-less rows at the same min_qty, which a plain unique key on a nullable column would let through', function () {
    $seed = aliSeed();
    $base = ['price_list_id' => $seed['list'], 'product_id' => $seed['product'], 'price' => '1', 'unit_id' => null];

    aliAdd([...$base, 'min_qty' => '5']);

    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => aliAdd([...$base, 'min_qty' => '5.0000']));
});
