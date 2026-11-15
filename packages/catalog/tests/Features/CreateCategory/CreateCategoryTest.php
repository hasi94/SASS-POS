<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateCategory;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Categories\CategoryPath;
use Flexipos\Catalog\Features\CreateCategory\CreateCategory;
use Flexipos\Catalog\Features\CreateCategory\CreateCategoryHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 9 - creating a category (AC-1, AC-2, AC-3a)
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
function createCategory(array $fields, ?string $actorId = null): string
{
    /** @var CreateCategoryHandler $handler */
    $handler = app(CreateCategoryHandler::class);

    return $handler->handle(new CreateCategory($fields, $actorId))->categoryId;
}

it('creates a root category and writes one audit row', function () {
    $id = createCategory(['code' => 'drinks', 'name' => 'Drinks']);

    $row = (array) CatalogSeed::connection()->table('categories')->where('id', $id)->first();

    expect($row['path'])->toBe($id)
        ->and((int) $row['depth'])->toBe(0)
        ->and((bool) $row['is_active'])->toBeTrue();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_category_created',
        'subject_type' => 'category',
        'subject_id' => $id,
    ]);
});

it('creates a child category whose path is parent/self (AC-1)', function () {
    $parent = createCategory(['code' => 'drinks', 'name' => 'Drinks']);
    $child = createCategory(['code' => 'soda', 'name' => 'Soda', 'parent_id' => $parent]);

    $row = (array) CatalogSeed::connection()->table('categories')->where('id', $child)->first();

    expect($row['path'])->toBe($parent.'/'.$child)
        ->and((int) $row['depth'])->toBe(1);
});

it('refuses a blank code', function () {
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createCategory(['code' => '', 'name' => 'X']));
});

it('refuses a duplicate code, including by case', function () {
    createCategory(['code' => 'drinks', 'name' => 'Drinks']);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createCategory(['code' => 'drinks', 'name' => 'Again']));
    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createCategory(['code' => 'DRINKS', 'name' => 'Again']));
});

it('refuses an unknown parent (AC-2)', function () {
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => createCategory(['code' => 'soda', 'name' => 'Soda', 'parent_id' => (string) Str::ulid()]));
});

it('refuses a soft-deleted parent (AC-2)', function () {
    $parent = createCategory(['code' => 'drinks', 'name' => 'Drinks']);
    CatalogSeed::connection()->table('categories')->where('id', $parent)->update(['deleted_at' => CatalogSeed::NOW]);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => createCategory(['code' => 'soda', 'name' => 'Soda', 'parent_id' => $parent]));
});

it('accepts a create at depth 17 and refuses one at depth 18, naming the row, writing nothing (AC-3a)', function () {
    $parentId = null;

    for ($i = 0; $i < 17; $i++) {
        $parentId = createCategory(['code' => 'level'.$i, 'name' => 'Level '.$i, 'parent_id' => $parentId]);
    }

    $row = (array) CatalogSeed::connection()->table('categories')->where('id', $parentId)->first();
    expect((int) $row['depth'])->toBe(16);

    // One more level (depth 17) is still accepted.
    $depth17 = createCategory(['code' => 'level17', 'name' => 'Level 17', 'parent_id' => $parentId]);
    $row17 = (array) CatalogSeed::connection()->table('categories')->where('id', $depth17)->first();
    expect((int) $row17['depth'])->toBe(17)
        ->and(CategoryPath::exceedsMax($row17['path']))->toBeFalse();

    $before = CatalogSeed::connection()->table('categories')->count();

    CatalogRefusals::expect(CatalogRefusal::TooDeep, fn () => createCategory(['code' => 'level18', 'name' => 'Level 18', 'parent_id' => $depth17]));

    expect(CatalogSeed::connection()->table('categories')->count())->toBe($before);
});
