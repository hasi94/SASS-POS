<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\ListProductBarcodes;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\AddProductBarcode\AddProductBarcode;
use Flexipos\Catalog\Features\AddProductBarcode\AddProductBarcodeHandler;
use Flexipos\Catalog\Features\ListProductBarcodes\ListProductBarcodes;
use Flexipos\Catalog\Features\ListProductBarcodes\ListProductBarcodesHandler;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 14 - listing a product's barcodes, primary first
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

it('lists a product\'s barcodes ordered with the primary first', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    /** @var AddProductBarcodeHandler $addHandler */
    $addHandler = app(AddProductBarcodeHandler::class);
    $addHandler->handle(new AddProductBarcode(['product_id' => $product, 'barcode' => 'B1']));
    $primary = $addHandler->handle(new AddProductBarcode(['product_id' => $product, 'barcode' => 'B2', 'is_primary' => true]));

    /** @var ListProductBarcodesHandler $handler */
    $handler = app(ListProductBarcodesHandler::class);
    $rows = $handler->handle(new ListProductBarcodes($product));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['id'])->toBe($primary->barcodeId);
});
