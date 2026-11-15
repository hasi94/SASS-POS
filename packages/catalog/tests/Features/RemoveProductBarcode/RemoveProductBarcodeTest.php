<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\RemoveProductBarcode;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\AddProductBarcode\AddProductBarcode;
use Flexipos\Catalog\Features\AddProductBarcode\AddProductBarcodeHandler;
use Flexipos\Catalog\Features\RemoveProductBarcode\RemoveProductBarcode;
use Flexipos\Catalog\Features\RemoveProductBarcode\RemoveProductBarcodeHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 14 - removing a product barcode (AC-28, AC-31)
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

function removeProductBarcode(string $barcodeId, ?string $actorId = null): void
{
    /** @var RemoveProductBarcodeHandler $handler */
    $handler = app(RemoveProductBarcodeHandler::class);

    $handler->handle(new RemoveProductBarcode($barcodeId, $actorId));
}

it('removes a barcode and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    /** @var AddProductBarcodeHandler $addHandler */
    $addHandler = app(AddProductBarcodeHandler::class);
    $barcodeId = $addHandler->handle(new AddProductBarcode(['product_id' => $product, 'barcode' => 'B1']))->barcodeId;

    removeProductBarcode($barcodeId);

    expect(CatalogSeed::connection()->table('product_barcodes')->where('id', $barcodeId)->exists())->toBeFalse();

    $audit = CatalogSeed::audit();
    expect($audit)->toHaveCount(2)
        ->and($audit[1]['action'])->toBe('catalog_product_barcode_removed');
});

it('refuses an unknown barcode', function () {
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => removeProductBarcode((string) Str::ulid()));
});
