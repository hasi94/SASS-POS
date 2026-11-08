<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateProductBranch;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateProductBranch\CreateProductBranch;
use Flexipos\Catalog\Features\CreateProductBranch\CreateProductBranchHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 15 - product branch settings (AC-33)
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
function createProductBranch(array $fields, ?string $actorId = null): void
{
    /** @var CreateProductBranchHandler $handler */
    $handler = app(CreateProductBranchHandler::class);

    $handler->handle(new CreateProductBranch($fields, $actorId));
}

it('leaves selling_price null (inherits), and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $branch = AccessSeed::branch();

    createProductBranch(['product_id' => $product, 'branch_id' => $branch]);

    $row = (array) CatalogSeed::connection()->table('product_branches')->where('product_id', $product)->where('branch_id', $branch)->first();
    expect($row['selling_price'])->toBeNull();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_product_branch_created',
        'subject_type' => 'product_branch',
        'subject_id' => "{$product}:{$branch}",
    ]);
});

it('accepts a selling_price of 0, and refuses a negative one', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $branch = AccessSeed::branch();

    createProductBranch(['product_id' => $product, 'branch_id' => $branch, 'selling_price' => '0.0000']);

    $row = (array) CatalogSeed::connection()->table('product_branches')->where('product_id', $product)->where('branch_id', $branch)->first();
    expect((float) $row['selling_price'])->toBe(0.0);
});

it('refuses a negative selling_price', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $branch = AccessSeed::branch();

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProductBranch(['product_id' => $product, 'branch_id' => $branch, 'selling_price' => '-1.0000']));
});

it('refuses a duplicate pair', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $branch = AccessSeed::branch();

    createProductBranch(['product_id' => $product, 'branch_id' => $branch]);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createProductBranch(['product_id' => $product, 'branch_id' => $branch]));
});

it('refuses an unknown branch', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => createProductBranch(['product_id' => $product, 'branch_id' => (string) Str::ulid()]));
});
