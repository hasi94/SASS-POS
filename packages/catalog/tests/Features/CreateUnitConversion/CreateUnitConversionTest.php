<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateUnitConversion;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateUnitConversion\CreateUnitConversion;
use Flexipos\Catalog\Features\CreateUnitConversion\CreateUnitConversionHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 12 - creating a unit conversion (AC-13, AC-13a, AC-14)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 14:00:00.000'));
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
function createUnitConversion(array $fields, ?string $actorId = null): string
{
    /** @var CreateUnitConversionHandler $handler */
    $handler = app(CreateUnitConversionHandler::class);

    return $handler->handle(new CreateUnitConversion($fields, $actorId))->conversionId;
}

it('creates a global conversion and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $case = CatalogSeed::unit('case');

    $id = createUnitConversion(['from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '24.000000']);

    $row = (array) CatalogSeed::connection()->table('unit_conversions')->where('id', $id)->first();
    expect($row['product_id'])->toBeNull();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_unit_conversion_created',
        'subject_type' => 'unit_conversion',
        'subject_id' => $id,
    ]);
});

it('refuses self-conversion', function () {
    $each = CatalogSeed::unit('each');

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnitConversion(['from_unit_id' => $each, 'to_unit_id' => $each, 'factor' => '1.000000']));
});

it('refuses cross-dimension', function () {
    $each = CatalogSeed::unit('each');
    $kg = CatalogSeed::unit('kg', 'mass');

    CatalogRefusals::expect(CatalogRefusal::DimensionMismatch, fn () => createUnitConversion(['from_unit_id' => $kg, 'to_unit_id' => $each, 'factor' => '1.000000']));
});

it('refuses a factor of 0 or less', function () {
    $each = CatalogSeed::unit('each');
    $case = CatalogSeed::unit('case');

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnitConversion(['from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '0.000000']));
});

it('refuses a duplicate global row, naming the existing row id; a global and a product row for the same pair coexist', function () {
    $each = CatalogSeed::unit('each');
    $case = CatalogSeed::unit('case');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    $existingId = createUnitConversion(['from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '24.000000']);

    $refused = CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createUnitConversion(['from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '12.000000']));
    expect($refused->getMessage())->toContain($existingId);

    $productRowId = createUnitConversion(['product_id' => $product, 'from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '12.000000']);
    expect($productRowId)->toBeString();
});

it('refuses an unknown or soft-deleted product (AC-14)', function () {
    $each = CatalogSeed::unit('each');
    $case = CatalogSeed::unit('case');

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => createUnitConversion(['product_id' => (string) Str::ulid(), 'from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '12.000000']));

    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each, ['deleted_at' => CatalogSeed::NOW]);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => createUnitConversion(['product_id' => $product, 'from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '12.000000']));
});

it('refuses a product-scoped row whose to_unit_id is not that product\'s own stock unit, naming it; a global row with the same to_unit is accepted (AC-13a)', function () {
    $each = CatalogSeed::unit('each');
    $case = CatalogSeed::unit('case');
    $dozen = CatalogSeed::unit('dozen');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    $refused = CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnitConversion([
        'product_id' => $product, 'from_unit_id' => $case, 'to_unit_id' => $dozen, 'factor' => '2.000000',
    ]));
    expect($refused->getMessage())->toContain($each);

    $id = createUnitConversion(['from_unit_id' => $case, 'to_unit_id' => $dozen, 'factor' => '2.000000']);
    expect($id)->toBeString();
});
