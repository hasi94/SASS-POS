<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\AddProductBarcode;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\AddProductBarcode\AddProductBarcode;
use Flexipos\Catalog\Features\AddProductBarcode\AddProductBarcodeHandler;
use Flexipos\Catalog\Features\AddProductBarcode\AddProductBarcodeResult;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 14 - adding a product barcode (AC-28 to AC-30)
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
function addProductBarcode(array $fields, ?string $actorId = null): AddProductBarcodeResult
{
    /** @var AddProductBarcodeHandler $handler */
    $handler = app(AddProductBarcodeHandler::class);

    return $handler->handle(new AddProductBarcode($fields, $actorId));
}

it('adds a barcode defaulting quantity to 1, and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    $result = addProductBarcode(['product_id' => $product, 'barcode' => '0123456789012']);

    $row = (array) CatalogSeed::connection()->table('product_barcodes')->where('id', $result->barcodeId)->first();
    expect((float) $row['quantity'])->toBe(1.0)
        ->and((bool) $row['is_primary'])->toBeFalse();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_product_barcode_added',
        'subject_type' => 'product_barcode',
        'subject_id' => $result->barcodeId,
    ]);
});

it('refuses a blank barcode and one over 64 characters', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => addProductBarcode(['product_id' => $product, 'barcode' => '']));
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => addProductBarcode(['product_id' => $product, 'barcode' => str_repeat('1', 65)]));
});

it('refuses a duplicate barcode on another product, naming that product\'s sku', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $productA = CatalogSeed::product('sku-a', $category, $each);
    $productB = CatalogSeed::product('sku-b', $category, $each);

    addProductBarcode(['product_id' => $productA, 'barcode' => 'DUPLICATE123']);

    $refused = CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => addProductBarcode(['product_id' => $productB, 'barcode' => 'DUPLICATE123']));
    expect($refused->getMessage())->toContain('sku-a');
});

it('refuses a unit_id of another dimension, naming the mismatch; the same dimension is accepted even with no conversion path (AC-29)', function () {
    $each = CatalogSeed::unit('each', 'count');
    $kg = CatalogSeed::unit('kg', 'mass');
    $case = CatalogSeed::unit('case', 'count');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::DimensionMismatch, fn () => addProductBarcode(['product_id' => $product, 'barcode' => 'B1', 'unit_id' => $kg]));

    $result = addProductBarcode(['product_id' => $product, 'barcode' => 'B2', 'unit_id' => $case]);
    expect($result->barcodeId)->toBeString();
});

it('clears the previous primary when a new one is added, inside one transaction (AC-30)', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    $a = addProductBarcode(['product_id' => $product, 'barcode' => 'BAR-A', 'is_primary' => true]);
    $b = addProductBarcode(['product_id' => $product, 'barcode' => 'BAR-B', 'is_primary' => true]);

    $rows = CatalogSeed::connection()->table('product_barcodes')->where('product_id', $product)->get();
    $primaries = $rows->where('is_primary', true)->pluck('id')->all();

    expect($primaries)->toBe([$b->barcodeId]);
});

it('writes nothing, including no audit row, on every refusal above', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    foreach ([
        ['product_id' => $product, 'barcode' => ''],
        ['product_id' => $product, 'extra' => 1],
    ] as $fields) {
        try {
            addProductBarcode($fields);
        } catch (\Throwable) {
            // expected
        }
    }

    expect(CatalogSeed::connection()->table('product_barcodes')->count())->toBe(0)
        ->and(CatalogSeed::audit())->toBe([]);
});
