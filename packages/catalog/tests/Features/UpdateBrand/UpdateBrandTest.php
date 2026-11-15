<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\UpdateBrand;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\UpdateBrand\UpdateBrand;
use Flexipos\Catalog\Features\UpdateBrand\UpdateBrandHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 10 - renaming a brand, uniqueness still applies (AC-7)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 12:00:00.000'));
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
function updateBrand(string $brandId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateBrandHandler $handler */
    $handler = app(UpdateBrandHandler::class);

    $handler->handle(new UpdateBrand($brandId, $fields, $actorId));
}

it('renames a brand and writes one audit row', function () {
    $id = CatalogSeed::brand('acme');

    updateBrand($id, ['name' => 'Acme Corp']);

    $row = (array) CatalogSeed::connection()->table('brands')->where('id', $id)->first();
    expect($row['name'])->toBe('Acme Corp');

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_brand_updated',
        'subject_type' => 'brand',
        'subject_id' => $id,
    ]);
});

it('refuses an unknown brand', function () {
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => updateBrand((string) Str::ulid(), ['name' => 'X']));
});

it('refuses renaming to a code or name already in use by another brand', function () {
    CatalogSeed::brand('acme');
    $other = CatalogSeed::brand('other');

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => updateBrand($other, ['code' => 'acme']));
    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => updateBrand($other, ['name' => 'Brand acme']));
});
