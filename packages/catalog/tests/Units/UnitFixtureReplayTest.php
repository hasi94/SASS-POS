<?php

declare(strict_types=1);

use Flexipos\Catalog\Units\SnapshotCalculator;
use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\ResolvedFactor;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;

/*
|--------------------------------------------------------------------------
| The shared unit-resolution fixtures (T-029 step 10, AC-24a, F-15, D-21)
|--------------------------------------------------------------------------
|
| T-006's standing obligation: unit resolution joins the shared PHP and
| TypeScript fixtures (docs/architecture.md section 15). The files in
| `packages/catalog/fixtures/units` hold the entered quantity and the unit's
| factor and precisions in, the snapshot (or the refusal) out, and FP-432 and
| FP-237 replay the same files. Every decimal and integer is a JSON string: a
| JavaScript number loses precision at the fourth decimal.
|
| This is deliberately SnapshotCalculator directly, not the resolver: the
| fixtures hold the arithmetic, which needs no database.
*/

/**
 * @return list<string>
 */
function unitFixturePaths(): array
{
    $paths = glob(dirname(__DIR__, 2).'/fixtures/units/*.json') ?: [];

    sort($paths);

    return $paths;
}

/**
 * @return array<string, array{string}>
 */
function unitFixtureDataset(): array
{
    $dataset = [];

    foreach (unitFixturePaths() as $path) {
        $dataset[basename($path)] = [$path];
    }

    return $dataset;
}

/**
 * Every reason this file is not a well-formed unit fixture.
 *
 * @return list<string>
 */
function unitFixtureLint(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);

    if (! is_array($decoded)) {
        return ['does not parse as a JSON object'];
    }

    $problems = [];

    foreach (['name', 'kind', 'discriminates', 'input'] as $key) {
        if (! array_key_exists($key, $decoded)) {
            $problems[] = "missing key [{$key}]";
        }
    }

    if (array_key_exists('expect', $decoded) === array_key_exists('refusal', $decoded)) {
        $problems[] = 'must hold exactly one of [expect] and [refusal]';
    }

    foreach (array_keys($decoded) as $key) {
        if (! in_array($key, ['name', 'kind', 'discriminates', 'input', 'expect', 'refusal'], true)) {
            $problems[] = "unexpected top-level key [{$key}]";
        }
    }

    if (($decoded['kind'] ?? null) !== 'unit') {
        $problems[] = 'kind must be unit';
    }

    if (($decoded['name'] ?? null) !== basename($path, '.json')) {
        $problems[] = 'name does not match the file name';
    }

    $walk = function (mixed $node, string $at) use (&$walk, &$problems): void {
        if (is_int($node) || is_float($node)) {
            $problems[] = "{$at} is a JSON number; decimals and integers must be strings";
        } elseif (is_string($node) && ! str_starts_with($at, '$.input.quantity') && preg_match('/^\d+\.\d+$/', $node) === 1 && preg_match('/^\d+\.(\d{4}|\d{6})$/', $node) !== 1) {
            $problems[] = "{$at} is a decimal [{$node}] without its full scale";
        } elseif (is_array($node)) {
            foreach ($node as $key => $child) {
                $walk($child, $at.'.'.$key);
            }
        }
    };

    $walk($decoded, '$');

    return $problems;
}

/**
 * @param  array<string, mixed>  $case
 * @return array{expect: array<string, string>}|array{refusal: string}
 */
function unitFixtureReplay(array $case): array
{
    /** @var array<string, string> $input */
    $input = $case['input'];

    // The product and stock-unit ids play no part in the arithmetic.
    $factor = new ResolvedFactor('01PRODUCT0000000000000000A', $input['unit_id'], '01STOCKUNIT00000000000000A', $input['factor'], FactorSource::from($input['factor_source']));

    try {
        $snapshot = SnapshotCalculator::compute(
            $factor,
            $input['unit_id'],
            $input['unit_code'],
            (int) $input['line_unit_precision'],
            (int) $input['stock_unit_precision'],
            $input['quantity'],
        );
    } catch (UnitResolutionRefused $refused) {
        return ['refusal' => $refused->reason->value];
    }

    return ['expect' => [
        'unit_id' => $snapshot->unitId,
        'unit_code' => $snapshot->unitCode,
        'source' => $snapshot->source->value,
        'quantity' => $snapshot->quantity,
        'unit_factor' => $snapshot->unitFactor,
        'base_quantity' => $snapshot->baseQuantity,
    ]];
}

/**
 * @param  array<string, mixed>  $case
 * @return array{expect: array<string, string>}|array{refusal: string}
 */
function unitFixtureExpected(array $case): array
{
    if (array_key_exists('refusal', $case)) {
        return ['refusal' => (string) $case['refusal']];
    }

    /** @var array<string, string> $expect */
    $expect = $case['expect'];

    ksort($expect);

    return ['expect' => $expect];
}

it('lints clean', function (string $path) {
    expect(unitFixtureLint($path))->toBe([]);
})->with(unitFixtureDataset());

it('replays through SnapshotCalculator to exactly what it expects', function (string $path) {
    /** @var array<string, mixed> $case */
    $case = json_decode((string) file_get_contents($path), true);

    $replayed = unitFixtureReplay($case);

    if (array_key_exists('expect', $replayed)) {
        ksort($replayed['expect']);
    }

    expect($replayed)->toBe(unitFixtureExpected($case));
})->with(unitFixtureDataset());

it('is not vacuous: at least eight fixtures, unique names, results and refusals both', function () {
    $names = [];
    $outcomes = [];

    foreach (unitFixturePaths() as $path) {
        /** @var array<string, mixed> $case */
        $case = json_decode((string) file_get_contents($path), true);
        $names[] = (string) $case['name'];
        $outcomes[array_key_exists('refusal', $case) ? 'refusal' : 'expect'] = true;
    }

    expect(count($names))->toBeGreaterThanOrEqual(8)
        ->and($names)->toBe(array_values(array_unique($names)))
        ->and(array_keys($outcomes))->toEqualCanonicalizing(['expect', 'refusal']);
});

it('control: a bare JSON number and a short decimal each fail the lint', function () {
    $directory = sys_get_temp_dir().'/flexipos-unit-fixture-scratch-'.bin2hex(random_bytes(6));
    mkdir($directory);

    try {
        foreach ([
            'scratch-number' => ['quantity' => 2.5],
            'scratch-scale' => ['factor' => '24.5'],
        ] as $name => $input) {
            $path = $directory.'/'.$name.'.json';
            file_put_contents($path, json_encode(['name' => $name, 'kind' => 'unit', 'discriminates' => 'x', 'input' => $input, 'refusal' => 'invalid_quantity'], JSON_THROW_ON_ERROR));

            expect(unitFixtureLint($path))->toHaveCount(1, $name);
            unlink($path);
        }
    } finally {
        rmdir($directory);
    }
});

it('control: a wrong expectation fails the replay', function () {
    /** @var array<string, mixed> $case */
    $case = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/units/unit-case-of-24.json'), true);

    expect(unitFixtureReplay($case)['expect'] ?? [])->toHaveKey('base_quantity');

    $case['expect']['base_quantity'] = '47.9999';

    $replayed = unitFixtureReplay($case);
    ksort($replayed['expect']);

    expect($replayed)->not->toBe(unitFixtureExpected($case));
});
