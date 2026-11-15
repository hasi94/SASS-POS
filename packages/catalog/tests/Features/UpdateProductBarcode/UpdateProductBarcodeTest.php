<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\UpdateProductBarcode;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\AddProductBarcode\AddProductBarcode;
use Flexipos\Catalog\Features\AddProductBarcode\AddProductBarcodeHandler;
use Flexipos\Catalog\Features\UpdateProductBarcode\UpdateProductBarcode;
use Flexipos\Catalog\Features\UpdateProductBarcode\UpdateProductBarcodeHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 14 - updating a product barcode (AC-29, AC-30)
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

/**
 * @param  array<string, mixed>  $fields
 */
function addBarcodeForUpdate(array $fields): string
{
    /** @var AddProductBarcodeHandler $handler */
    $handler = app(AddProductBarcodeHandler::class);

    return $handler->handle(new AddProductBarcode($fields))->barcodeId;
}

/**
 * @param  array<string, mixed>  $fields
 */
function updateProductBarcode(string $barcodeId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateProductBarcodeHandler $handler */
    $handler = app(UpdateProductBarcodeHandler::class);

    $handler->handle(new UpdateProductBarcode($barcodeId, $fields, $actorId));
}

it('updates quantity and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $barcodeId = addBarcodeForUpdate(['product_id' => $product, 'barcode' => 'B1']);

    updateProductBarcode($barcodeId, ['quantity' => '2.0000']);

    $row = (array) CatalogSeed::connection()->table('product_barcodes')->where('id', $barcodeId)->first();
    expect((float) $row['quantity'])->toBe(2.0);

    $audit = CatalogSeed::audit();
    expect($audit)->toHaveCount(2)
        ->and($audit[1])->toMatchArray([
            'module' => 'catalog',
            'action' => 'catalog_product_barcode_updated',
            'subject_type' => 'product_barcode',
            'subject_id' => $barcodeId,
        ]);
});

it('refuses a unit_id of another dimension (AC-29)', function () {
    $each = CatalogSeed::unit('each', 'count');
    $kg = CatalogSeed::unit('kg', 'mass');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $barcodeId = addBarcodeForUpdate(['product_id' => $product, 'barcode' => 'B1']);

    CatalogRefusals::expect(CatalogRefusal::DimensionMismatch, fn () => updateProductBarcode($barcodeId, ['unit_id' => $kg]));
});

it('clears the other primary when set true (AC-30)', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $a = addBarcodeForUpdate(['product_id' => $product, 'barcode' => 'BAR-A', 'is_primary' => true]);
    $b = addBarcodeForUpdate(['product_id' => $product, 'barcode' => 'BAR-B']);

    updateProductBarcode($b, ['is_primary' => true]);

    $rows = CatalogSeed::connection()->table('product_barcodes')->where('product_id', $product)->get();
    expect($rows->where('is_primary', true)->pluck('id')->all())->toBe([$b]);
});

it('refuses an empty update and an unknown barcode', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $barcodeId = addBarcodeForUpdate(['product_id' => $product, 'barcode' => 'B1']);

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => updateProductBarcode($barcodeId, []));
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => updateProductBarcode((string) Str::ulid(), ['quantity' => '2.0000']));
});
