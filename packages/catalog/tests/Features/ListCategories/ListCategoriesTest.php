<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\ListCategories;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\ListCategories\ListCategories;
use Flexipos\Catalog\Features\ListCategories\ListCategoriesHandler;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 9 - listing live categories, ordered by path
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

it('excludes soft-deleted rows and orders by path', function () {
    $root = CatalogSeed::category('b-root');
    $child = CatalogSeed::category('a-child', $root);
    CatalogSeed::category('deleted', null, ['deleted_at' => CatalogSeed::NOW]);

    /** @var ListCategoriesHandler $handler */
    $handler = app(ListCategoriesHandler::class);
    $rows = $handler->handle(new ListCategories);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['id'])->toBe($root)
        ->and($rows[1]['id'])->toBe($child);
});
