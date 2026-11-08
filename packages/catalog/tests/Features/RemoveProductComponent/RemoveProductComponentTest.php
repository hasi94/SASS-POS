<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\RemoveProductComponent;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\AddProductComponent\AddProductComponent;
use Flexipos\Catalog\Features\AddProductComponent\AddProductComponentHandler;
use Flexipos\Catalog\Features\RemoveProductComponent\RemoveProductComponent;
use Flexipos\Catalog\Features\RemoveProductComponent\RemoveProductComponentHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 16 - removing a product component (AC-34)
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

it('removes the row and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $parent = CatalogSeed::product('bundle', $category, $each);
    $component = CatalogSeed::product('part', $category, $each);

    /** @var AddProductComponentHandler $addHandler */
    $addHandler = app(AddProductComponentHandler::class);
    $componentId = $addHandler->handle(new AddProductComponent([
        'parent_product_id' => $parent, 'component_product_id' => $component, 'unit_id' => $each, 'quantity' => 1,
    ]))->componentId;

    /** @var RemoveProductComponentHandler $handler */
    $handler = app(RemoveProductComponentHandler::class);
    $handler->handle(new RemoveProductComponent($componentId));

    expect(CatalogSeed::connection()->table('product_components')->where('id', $componentId)->exists())->toBeFalse();

    $audit = CatalogSeed::audit();
    expect($audit)->toHaveCount(2)
        ->and($audit[1]['action'])->toBe('catalog_product_component_removed');
});

it('refuses an unknown component row', function () {
    /** @var RemoveProductComponentHandler $handler */
    $handler = app(RemoveProductComponentHandler::class);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => $handler->handle(new RemoveProductComponent((string) Str::ulid())));
});
