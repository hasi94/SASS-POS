<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Flexipos\Pricing\Tests\Support\FixtureReplay;

/*
|--------------------------------------------------------------------------
| The shared fixture set (T-029 step 10, AC-24, D-9, D-21)
|--------------------------------------------------------------------------
|
| Three jobs, one per block: the LINT (every file is a well-formed fixture:
| strings only, never a JSON number), the REPLAY (every file in the
| directory, found by glob, runs through the pure class its kind names and
| equals its expectation or its refusal), and ANTI-VACUITY (the directory is
| not empty and covers every kind). Each carries a control below that proves
| it can fail.
|
| The fixtures are hand-derived ground truth for FP-432's TypeScript twin, so
| the replay compares the engine against them and never the reverse.
*/

/**
 * @return array<string, array{string}>
 */
function fixtureSetDataset(): array
{
    $dataset = [];
    $root = FixtureReplay::directory();

    foreach (FixtureReplay::paths() as $path) {
        $dataset[substr($path, strlen($root) + 1)] = [$path];
    }

    return $dataset;
}

function fixtureSetScratch(string $kind, string $name, string $body): string
{
    $directory = sys_get_temp_dir().'/flexipos-fixture-scratch-'.bin2hex(random_bytes(6));
    mkdir($directory.'/'.$kind, 0777, true);
    $path = $directory.'/'.$kind.'/'.$name.'.json';
    file_put_contents($path, $body);

    return $path;
}

function fixtureSetCleanup(string $path): void
{
    unlink($path);
    rmdir(dirname($path));
    rmdir(dirname($path, 2));
}

it('lints clean', function (string $path) {
    expect(FixtureReplay::lint($path))->toBe([]);
})->with(fixtureSetDataset());

it('replays to exactly what it expects', function (string $path) {
    /** @var array<string, mixed> $case */
    $case = json_decode((string) file_get_contents($path), true);

    expect(FixtureReplay::replay($case))->toBe(FixtureReplay::expected($case));
})->with(fixtureSetDataset());

it('is not vacuous: at least thirty fixtures, every kind present, every name unique', function () {
    $paths = FixtureReplay::paths();
    $kinds = [];
    $names = [];

    foreach ($paths as $path) {
        /** @var array<string, mixed> $case */
        $case = json_decode((string) file_get_contents($path), true);
        $kinds[(string) $case['kind']] = ($kinds[(string) $case['kind']] ?? 0) + 1;
        $names[] = (string) $case['name'];
    }

    expect(count($paths))->toBeGreaterThanOrEqual(30)
        ->and(array_keys($kinds))->toEqualCanonicalizing(FixtureReplay::KINDS)
        ->and($names)->toBe(array_values(array_unique($names)));
});

it('covers both outcomes: some fixtures expect a result and some expect a refusal, per kind', function () {
    $outcomes = [];

    foreach (FixtureReplay::paths() as $path) {
        /** @var array<string, mixed> $case */
        $case = json_decode((string) file_get_contents($path), true);
        $outcomes[(string) $case['kind']][array_key_exists('refusal', $case) ? 'refusal' : 'expect'] = true;
    }

    foreach (FixtureReplay::KINDS as $kind) {
        expect($outcomes[$kind] ?? [])->toHaveKey('expect');
    }

    foreach (['tax', 'price', 'discount', 'line', 'bill'] as $kind) {
        expect($outcomes[$kind] ?? [])->toHaveKey('refusal');
    }
});

/*
|--------------------------------------------------------------------------
| Controls: the lint and the replay can fail
|--------------------------------------------------------------------------
*/

it('control: a fixture holding a bare JSON number fails the lint', function () {
    $path = fixtureSetScratch('price', 'scratch-number', json_encode([
        'name' => 'scratch-number', 'kind' => 'price', 'discriminates' => 'x',
        'input' => ['selling_price' => 7.5],
        'refusal' => 'no_price_resolved',
    ], JSON_THROW_ON_ERROR));

    try {
        $problems = FixtureReplay::lint($path);

        expect($problems)->toHaveCount(1)
            ->and($problems[0])->toContain('JSON number')
            ->and($problems[0])->toContain('selling_price');
    } finally {
        fixtureSetCleanup($path);
    }
});

it('control: an integer is also a JSON number and fails the lint', function () {
    $path = fixtureSetScratch('price', 'scratch-integer', json_encode([
        'name' => 'scratch-integer', 'kind' => 'price', 'discriminates' => 'x',
        'input' => ['priority' => 5],
        'refusal' => 'no_price_resolved',
    ], JSON_THROW_ON_ERROR));

    try {
        expect(FixtureReplay::lint($path))->toHaveCount(1);
    } finally {
        fixtureSetCleanup($path);
    }
});

it('control: a decimal string short of its full scale fails the lint', function () {
    $path = fixtureSetScratch('price', 'scratch-scale', json_encode([
        'name' => 'scratch-scale', 'kind' => 'price', 'discriminates' => 'x',
        'input' => ['selling_price' => '7.5'],
        'refusal' => 'no_price_resolved',
    ], JSON_THROW_ON_ERROR));

    try {
        $problems = FixtureReplay::lint($path);

        expect($problems)->toHaveCount(1)
            ->and($problems[0])->toContain('full scale');
    } finally {
        fixtureSetCleanup($path);
    }
});

it('control: a malformed key set, a wrong name and a wrong directory each fail the lint', function () {
    $body = static fn (array $overrides): string => json_encode([...[
        'name' => 'scratch-shape', 'kind' => 'price', 'discriminates' => 'x', 'input' => [], 'refusal' => 'no_price_resolved',
    ], ...$overrides], JSON_THROW_ON_ERROR);

    $cases = [
        'unexpected key' => [['extra' => 'y'], 'unexpected top-level key'],
        'both expect and refusal' => [['expect' => []], 'exactly one of'],
        'wrong name' => [['name' => 'another-name'], 'name does not match'],
        'wrong kind for the directory' => [['kind' => 'tax'], 'does not match its directory'],
        'unknown kind' => [['kind' => 'nonsense'], 'kind is not one of'],
    ];

    foreach ($cases as $label => [$overrides, $expected]) {
        $path = fixtureSetScratch('price', 'scratch-shape', $body($overrides));

        try {
            expect(str_contains(implode('|', FixtureReplay::lint($path)), $expected))->toBeTrue("case [{$label}]");
        } finally {
            fixtureSetCleanup($path);
        }
    }
});

it('control: a fixture with a wrong expectation fails the replay', function () {
    $case = [
        'name' => 'scratch-wrong', 'kind' => 'bill', 'discriminates' => 'x',
        'input' => [
            'discount_id' => '01BILL1000000000000000000A',
            'discounts' => [[
                'id' => '01BILL1000000000000000000A', 'type' => 'percentage', 'value' => '5.0000', 'scope' => 'bill',
                'is_active' => true, 'starts_at' => null, 'ends_at' => null, 'usage_limit' => null, 'used_count' => '0',
                'min_amount' => null, 'max_discount' => null, 'applies_to' => ['products' => [], 'categories' => [], 'brands' => []],
            ]],
            'at' => '2025-06-15T09:30:00.000Z',
            'discounted_subtotals' => ['100.0000'],
        ],
        'expect' => ['discount_id' => '01BILL1000000000000000000A', 'base' => '100.0000', 'amount' => '5.0000'],
    ];

    expect(FixtureReplay::replay($case))->toBe(FixtureReplay::expected($case));

    $case['expect']['amount'] = '5.0001';

    expect(FixtureReplay::replay($case))->not->toBe(FixtureReplay::expected($case));

    $refusing = $case;
    unset($refusing['expect']);
    $refusing['refusal'] = 'expired';

    expect(FixtureReplay::replay($refusing))->not->toBe(FixtureReplay::expected($refusing));
});
