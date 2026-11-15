<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\RemoveProductBranch;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateProductBranch\CreateProductBranch;
use Flexipos\Catalog\Features\CreateProductBranch\CreateProductBranchHandler;
use Flexipos\Catalog\Features\RemoveProductBranch\RemoveProductBranch;
use Flexipos\Catalog\Features\RemoveProductBranch\RemoveProductBranchHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 15 - removing product branch settings (AC-33)
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
    $product = CatalogSeed::product('sku1', $category, $each);
    $branch = AccessSeed::branch();

    /** @var CreateProductBranchHandler $createHandler */
    $createHandler = app(CreateProductBranchHandler::class);
    $createHandler->handle(new CreateProductBranch(['product_id' => $product, 'branch_id' => $branch]));

    /** @var RemoveProductBranchHandler $handler */
    $handler = app(RemoveProductBranchHandler::class);
    $handler->handle(new RemoveProductBranch($product, $branch));

    expect(CatalogSeed::connection()->table('product_branches')->where('product_id', $product)->where('branch_id', $branch)->exists())->toBeFalse();

    $audit = CatalogSeed::audit();
    expect($audit)->toHaveCount(2)
        ->and($audit[1]['action'])->toBe('catalog_product_branch_removed');
});

it('refuses an unknown pair', function () {
    /** @var RemoveProductBranchHandler $handler */
    $handler = app(RemoveProductBranchHandler::class);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => $handler->handle(new RemoveProductBranch((string) Str::ulid(), (string) Str::ulid())));
});
