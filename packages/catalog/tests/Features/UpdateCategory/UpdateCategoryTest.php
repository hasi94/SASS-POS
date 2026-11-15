<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\UpdateCategory;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\UpdateCategory\UpdateCategory;
use Flexipos\Catalog\Features\UpdateCategory\UpdateCategoryHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 9 - updating/moving a category (AC-1, AC-3, AC-4)
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
function updateCategory(string $categoryId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateCategoryHandler $handler */
    $handler = app(UpdateCategoryHandler::class);

    $handler->handle(new UpdateCategory($categoryId, $fields, $actorId));
}

it('updates name and is_active, and writes one audit row', function () {
    $id = CatalogSeed::category('drinks');

    updateCategory($id, ['name' => 'Beverages', 'is_active' => false]);

    $row = (array) CatalogSeed::connection()->table('categories')->where('id', $id)->first();

    expect($row['name'])->toBe('Beverages')
        ->and((bool) $row['is_active'])->toBeFalse();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_category_updated',
        'subject_type' => 'category',
        'subject_id' => $id,
    ]);
});

it('refuses an update naming no field', function () {
    $id = CatalogSeed::category('drinks');

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => updateCategory($id, []));
});

it('refuses an unknown category', function () {
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => updateCategory((string) Str::ulid(), ['name' => 'X']));
});

it('moves a three-level-deep subtree under another root, rewriting every descendant path and depth, including a soft-deleted one (AC-3)', function () {
    $rootA = CatalogSeed::category('a');
    $rootB = CatalogSeed::category('b');
    $mid = CatalogSeed::category('mid', $rootA);
    $leaf = CatalogSeed::category('leaf', $mid);
    $deletedLeaf = CatalogSeed::category('deleted-leaf', $mid, ['deleted_at' => CatalogSeed::NOW]);
    $unrelated = CatalogSeed::category('unrelated', $rootB);

    $unrelatedBefore = (array) CatalogSeed::connection()->table('categories')->where('id', $unrelated)->first();

    updateCategory($mid, ['parent_id' => $rootB]);

    $midRow = (array) CatalogSeed::connection()->table('categories')->where('id', $mid)->first();
    $leafRow = (array) CatalogSeed::connection()->table('categories')->where('id', $leaf)->first();
    $deletedLeafRow = (array) CatalogSeed::connection()->table('categories')->where('id', $deletedLeaf)->first();
    $unrelatedAfter = (array) CatalogSeed::connection()->table('categories')->where('id', $unrelated)->first();

    expect($midRow['path'])->toBe($rootB.'/'.$mid)
        ->and((int) $midRow['depth'])->toBe(1)
        ->and($leafRow['path'])->toBe($rootB.'/'.$mid.'/'.$leaf)
        ->and((int) $leafRow['depth'])->toBe(2)
        ->and($deletedLeafRow['path'])->toBe($rootB.'/'.$mid.'/'.$deletedLeaf)
        ->and((int) $deletedLeafRow['depth'])->toBe(2)
        ->and($unrelatedAfter)->toBe($unrelatedBefore);
});

it('refuses moving a category under itself, and under its own grandchild (AC-4)', function () {
    $root = CatalogSeed::category('root');
    $mid = CatalogSeed::category('mid', $root);
    $leaf = CatalogSeed::category('leaf', $mid);

    CatalogRefusals::expect(CatalogRefusal::Cycle, fn () => updateCategory($root, ['parent_id' => $root]));
    CatalogRefusals::expect(CatalogRefusal::Cycle, fn () => updateCategory($root, ['parent_id' => $leaf]));
});

it('refuses a create or move that would push the deepest descendant past 500 characters, naming that row, writing nothing (AC-3a); accepts the same depth as a control', function () {
    $otherRoot = CatalogSeed::category('other');
    $parentId = $otherRoot;

    for ($i = 0; $i < 16; $i++) {
        $parentId = CatalogSeed::category('level'.$i, $parentId);
    }

    // $parentId is now at depth 16; one more level is depth 17 (control, accepted).
    $deep = CatalogSeed::category('deep16', $parentId);
    $deepRow = (array) CatalogSeed::connection()->table('categories')->where('id', $deep)->first();
    expect((int) $deepRow['depth'])->toBe(17);

    // Moving a lone root under $deep would push it to depth 18: refused.
    $mover = CatalogSeed::category('mover');

    $before = CatalogSeed::connection()->table('categories')->get()->map(fn ($r) => (array) $r)->all();

    CatalogRefusals::expect(CatalogRefusal::TooDeep, fn () => updateCategory($mover, ['parent_id' => $deep]));

    $after = CatalogSeed::connection()->table('categories')->get()->map(fn ($r) => (array) $r)->all();
    expect($after)->toBe($before);

    // Control: moving under the depth-16 parent (one level shallower) succeeds.
    updateCategory($mover, ['parent_id' => $parentId]);
    $moverRow = (array) CatalogSeed::connection()->table('categories')->where('id', $mover)->first();
    expect((int) $moverRow['depth'])->toBe(17);
});

it('refuses a duplicate code on update', function () {
    CatalogSeed::category('drinks');
    $snacks = CatalogSeed::category('snacks');

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => updateCategory($snacks, ['code' => 'drinks']));
});
