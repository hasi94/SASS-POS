<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateBrand;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateBrand\CreateBrand;
use Flexipos\Catalog\Features\CreateBrand\CreateBrandHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 10 - creating a brand (AC-7)
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
function createBrand(array $fields, ?string $actorId = null): string
{
    /** @var CreateBrandHandler $handler */
    $handler = app(CreateBrandHandler::class);

    return $handler->handle(new CreateBrand($fields, $actorId))->brandId;
}

it('creates a brand and writes one audit row', function () {
    $id = createBrand(['code' => 'acme', 'name' => 'Acme']);

    $row = (array) CatalogSeed::connection()->table('brands')->where('id', $id)->first();
    expect($row['code'])->toBe('acme')->and($row['name'])->toBe('Acme');

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_brand_created',
        'subject_type' => 'brand',
        'subject_id' => $id,
    ]);
});

it('refuses a code shared with a different name (AC-7)', function () {
    createBrand(['code' => 'acme', 'name' => 'Acme']);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createBrand(['code' => 'acme', 'name' => 'Different']));
});

it('refuses a name shared with a different code (AC-7)', function () {
    createBrand(['code' => 'acme', 'name' => 'Acme']);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createBrand(['code' => 'different', 'name' => 'Acme']));
});
