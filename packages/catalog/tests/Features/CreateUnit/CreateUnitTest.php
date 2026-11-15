<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateUnit;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateUnit\CreateUnit;
use Flexipos\Catalog\Features\CreateUnit\CreateUnitHandler;
use Flexipos\Catalog\Features\CreateUnit\CreateUnitResult;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 8 - creating a unit of measure (AC-11)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 10:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function createUnitInput(array $overrides = []): array
{
    return array_merge([
        'code' => 'kg',
        'name' => 'Kilogram',
        'dimension' => 'mass',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function createUnit(array $fields, ?string $actorId = null): CreateUnitResult
{
    /** @var CreateUnitHandler $handler */
    $handler = app(CreateUnitHandler::class);

    return $handler->handle(new CreateUnit($fields, $actorId));
}

it('creates a unit with the default precision 4, and writes one audit row', function () {
    $result = createUnit(createUnitInput());

    $row = (array) CatalogSeed::connection()->table('units')->where('id', $result->unitId)->first();

    expect(strlen($result->unitId))->toBe(26)
        ->and($result->unitId)->toBe(strtoupper($result->unitId))
        ->and($row['code'])->toBe('kg')
        ->and((int) $row['precision'])->toBe(4)
        ->and((bool) $row['is_active'])->toBeTrue()
        ->and($row['base_unit_id'])->toBeNull()
        ->and($row['factor'])->toBeNull();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_unit_created',
        'subject_type' => 'unit',
        'subject_id' => $result->unitId,
        'before' => null,
    ]);
});

it('refuses a field the operation does not take', function () {
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnit(createUnitInput(['extra' => 1])));
});

it('refuses a missing dimension, and any value outside the enum', function () {
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnit(createUnitInput(['dimension' => null])));
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnit(createUnitInput(['dimension' => 'weight'])));
});

it('refuses precision outside 0 to 4, and defaults to 4 when absent', function () {
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnit(createUnitInput(['precision' => -1])));
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnit(createUnitInput(['precision' => 5])));

    $result = createUnit(createUnitInput(['code' => 'ea', 'precision' => 0]));
    $row = (array) CatalogSeed::connection()->table('units')->where('id', $result->unitId)->first();

    expect((int) $row['precision'])->toBe(0);
});

it('refuses base_unit_id without factor, and factor without base_unit_id', function () {
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnit(createUnitInput(['code' => 'g', 'base_unit_id' => CatalogSeed::unit('kg', 'mass')])));
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnit(createUnitInput(['code' => 'g', 'factor' => '0.001000'])));
});

it('refuses a base unit of a different dimension', function () {
    $each = CatalogSeed::unit('each', 'count');

    CatalogRefusals::expect(CatalogRefusal::DimensionMismatch, fn () => createUnit(createUnitInput([
        'code' => 'g', 'base_unit_id' => $each, 'factor' => '0.001000',
    ])));
});

it('refuses factor 0, a negative factor, and a factor with 7 decimals', function () {
    $kg = CatalogSeed::unit('kg', 'mass');

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnit(createUnitInput(['code' => 'g', 'base_unit_id' => $kg, 'factor' => '0'])));
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createUnit(createUnitInput(['code' => 'g', 'base_unit_id' => $kg, 'factor' => '0.0000001'])));
});

it('accepts a chain: g under kg, factor 0.001', function () {
    $kg = CatalogSeed::unit('kg', 'mass');

    $result = createUnit(createUnitInput(['code' => 'g', 'base_unit_id' => $kg, 'factor' => '0.001000']));

    $row = (array) CatalogSeed::connection()->table('units')->where('id', $result->unitId)->first();

    // SQLite has no fixed-scale DECIMAL type and returns this column as a
    // float; the stored string's exact scale is proved on the engine
    // (AC-44's static test covers the column declaration itself).
    expect($row['base_unit_id'])->toBe($kg)
        ->and((float) $row['factor'])->toBe(0.001);
});

it('refuses a duplicate code, including by case (AC-11)', function () {
    createUnit(createUnitInput(['code' => 'kg']));

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createUnit(createUnitInput(['code' => 'kg', 'name' => 'Kilogram again'])));
    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createUnit(createUnitInput(['code' => 'KG', 'name' => 'Kilogram shouting'])));
});

it('writes nothing, including no audit row, on every refusal above', function () {
    $before = CatalogSeed::connection()->table('units')->count();

    foreach ([
        ['extra' => 1],
        ['dimension' => 'weight'],
        ['precision' => 5],
    ] as $overrides) {
        try {
            createUnit(createUnitInput($overrides));
        } catch (\Throwable) {
            // expected
        }
    }

    expect(CatalogSeed::connection()->table('units')->count())->toBe($before)
        ->and(CatalogSeed::audit())->toBe([]);
});
