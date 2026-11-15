<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\GetCategory;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\GetCategory\GetCategory;
use Flexipos\Catalog\Features\GetCategory\GetCategoryHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 9 - reading one category by id, soft-deleted or not
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

it('returns a live category by id', function () {
    $id = CatalogSeed::category('drinks');

    /** @var GetCategoryHandler $handler */
    $handler = app(GetCategoryHandler::class);
    $row = $handler->handle(new GetCategory($id));

    expect($row['id'])->toBe($id)->and($row['code'])->toBe('drinks');
});

it('returns a soft-deleted category by id', function () {
    $id = CatalogSeed::category('drinks', null, ['deleted_at' => CatalogSeed::NOW]);

    /** @var GetCategoryHandler $handler */
    $handler = app(GetCategoryHandler::class);
    $row = $handler->handle(new GetCategory($id));

    expect($row['id'])->toBe($id)->and($row['deleted_at'])->not->toBeNull();
});

it('refuses an unknown id', function () {
    /** @var GetCategoryHandler $handler */
    $handler = app(GetCategoryHandler::class);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => $handler->handle(new GetCategory((string) Str::ulid())));
});
