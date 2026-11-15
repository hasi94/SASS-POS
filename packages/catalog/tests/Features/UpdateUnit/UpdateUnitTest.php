<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\UpdateUnit;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\UpdateUnit\UpdateUnit;
use Flexipos\Catalog\Features\UpdateUnit\UpdateUnitHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 8 - updating a unit of measure (AC-11)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 11:00:00.000'));
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
function updateUnit(string $unitId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateUnitHandler $handler */
    $handler = app(UpdateUnitHandler::class);

    $handler->handle(new UpdateUnit($unitId, $fields, $actorId));
}

it('updates name, precision and is_active, and writes one audit row', function () {
    $unitId = CatalogSeed::unit('kg', 'mass', ['precision' => 3]);

    updateUnit($unitId, ['name' => 'Kilogramme', 'precision' => 2, 'is_active' => false]);

    $row = (array) CatalogSeed::connection()->table('units')->where('id', $unitId)->first();

    expect($row['name'])->toBe('Kilogramme')
        ->and((int) $row['precision'])->toBe(2)
        ->and((bool) $row['is_active'])->toBeFalse();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_unit_updated',
        'subject_type' => 'unit',
        'subject_id' => $unitId,
    ]);
});

it('refuses an update naming no field', function () {
    $unitId = CatalogSeed::unit('kg', 'mass');

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => updateUnit($unitId, []));
});

it('refuses an unknown unit', function () {
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => updateUnit((string) Str::ulid(), ['name' => 'X']));
});

it('refuses precision outside 0 to 4', function () {
    $unitId = CatalogSeed::unit('kg', 'mass');

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => updateUnit($unitId, ['precision' => -1]));
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => updateUnit($unitId, ['precision' => 5]));
});

it('refuses a duplicate code, including by case', function () {
    CatalogSeed::unit('kg', 'mass');
    $g = CatalogSeed::unit('g', 'mass');

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => updateUnit($g, ['code' => 'kg']));
    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => updateUnit($g, ['code' => 'KG']));
});

it('accepts renaming a unit to its own current code (a no-op)', function () {
    $kg = CatalogSeed::unit('kg', 'mass');

    updateUnit($kg, ['code' => 'kg', 'name' => 'Kilogram renamed']);

    $row = (array) CatalogSeed::connection()->table('units')->where('id', $kg)->first();

    expect($row['name'])->toBe('Kilogram renamed');
});

it('refuses base_unit_id submitted without factor, and factor without base_unit_id', function () {
    $kg = CatalogSeed::unit('kg', 'mass');
    $g = CatalogSeed::unit('g', 'mass');

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => updateUnit($g, ['base_unit_id' => $kg]));
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => updateUnit($g, ['factor' => '1.000000']));
});

it('refuses a cross-dimension base on update', function () {
    $each = CatalogSeed::unit('each', 'count');
    $g = CatalogSeed::unit('g', 'mass');

    CatalogRefusals::expect(CatalogRefusal::DimensionMismatch, fn () => updateUnit($g, ['base_unit_id' => $each, 'factor' => '1.000000']));
});

it('refuses a changed dimension', function () {
    $kg = CatalogSeed::unit('kg', 'mass');

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => updateUnit($kg, ['dimension' => 'volume']));
});

it('refuses a two-unit cycle: A points at B, then B is re-pointed at A', function () {
    $a = CatalogSeed::unit('a', 'count');
    $b = CatalogSeed::unit('b', 'count', ['base_unit_id' => $a, 'factor' => '2.000000']);

    CatalogRefusals::expect(CatalogRefusal::Cycle, fn () => updateUnit($a, ['base_unit_id' => $b, 'factor' => '2.000000']));
});

it('refuses a three-unit cycle: C -> B -> A, then A re-pointed at C', function () {
    $a = CatalogSeed::unit('a', 'count');
    $b = CatalogSeed::unit('b', 'count', ['base_unit_id' => $a, 'factor' => '2.000000']);
    $c = CatalogSeed::unit('c', 'count', ['base_unit_id' => $b, 'factor' => '3.000000']);

    CatalogRefusals::expect(CatalogRefusal::Cycle, fn () => updateUnit($a, ['base_unit_id' => $c, 'factor' => '2.000000']));
});

it('accepts re-pointing one branch to another root, which closes no cycle (control)', function () {
    $a = CatalogSeed::unit('a', 'count');
    $root2 = CatalogSeed::unit('root2', 'count');
    $b = CatalogSeed::unit('b', 'count', ['base_unit_id' => $a, 'factor' => '2.000000']);
    $c = CatalogSeed::unit('c', 'count', ['base_unit_id' => $b, 'factor' => '3.000000']);

    updateUnit($c, ['base_unit_id' => $root2, 'factor' => '5.000000']);

    $row = (array) CatalogSeed::connection()->table('units')->where('id', $c)->first();

    expect($row['base_unit_id'])->toBe($root2);
});

it('writes nothing, including no audit row, on every refusal above', function () {
    $kg = CatalogSeed::unit('kg', 'mass');
    $before = CatalogSeed::connection()->table('units')->get()->map(fn (object $row): array => (array) $row)->all();

    foreach ([
        [],
        ['precision' => 5],
        ['dimension' => 'volume'],
    ] as $fields) {
        try {
            updateUnit($kg, $fields);
        } catch (\Throwable) {
            // expected
        }
    }

    $after = CatalogSeed::connection()->table('units')->get()->map(fn (object $row): array => (array) $row)->all();

    expect($after)->toBe($before)
        ->and(CatalogSeed::audit())->toBe([]);
});
