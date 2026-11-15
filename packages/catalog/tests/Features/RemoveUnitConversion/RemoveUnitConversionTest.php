<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\RemoveUnitConversion;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\RemoveUnitConversion\RemoveUnitConversion;
use Flexipos\Catalog\Features\RemoveUnitConversion\RemoveUnitConversionHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 12 - removing a unit conversion (AC-13, AC-14)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('removes a conversion row and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $case = CatalogSeed::unit('case');
    $id = (string) Str::ulid();

    CatalogSeed::connection()->table('unit_conversions')->insert([
        'id' => $id, 'product_id' => null, 'from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '24.000000',
    ]);

    /** @var RemoveUnitConversionHandler $handler */
    $handler = app(RemoveUnitConversionHandler::class);
    $handler->handle(new RemoveUnitConversion($id));

    expect(CatalogSeed::connection()->table('unit_conversions')->where('id', $id)->exists())->toBeFalse();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_unit_conversion_removed',
        'subject_type' => 'unit_conversion',
        'subject_id' => $id,
    ]);
});

it('refuses an unknown conversion', function () {
    /** @var RemoveUnitConversionHandler $handler */
    $handler = app(RemoveUnitConversionHandler::class);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => $handler->handle(new RemoveUnitConversion((string) Str::ulid())));
});
