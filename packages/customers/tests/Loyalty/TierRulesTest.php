<?php

declare(strict_types=1);

use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Loyalty\TierRules;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| TierRules (plan step 7, Ruling 5, D-22, F-5)
|--------------------------------------------------------------------------
*/

/**
 * @return list<array{tier: string, min_points: string}>
 */
function tierRulesFixture(): array
{
    return [
        ['tier' => 'bronze', 'min_points' => '0'],
        ['tier' => 'silver', 'min_points' => '100'],
        ['tier' => 'gold', 'min_points' => '500'],
    ];
}

it('parses a valid list', function () {
    $parsed = TierRules::parse(tierRulesFixture());

    expect($parsed)->toBe([
        ['tier' => 'bronze', 'min_points' => '0.0000'],
        ['tier' => 'silver', 'min_points' => '100.0000'],
        ['tier' => 'gold', 'min_points' => '500.0000'],
    ]);
});

it('parses null as null', function () {
    expect(TierRules::parse(null))->toBeNull();
});

it('refuses an empty list', function () {
    expect(fn () => TierRules::parse([]))->toThrow(CustomersRefused::class);
});

it('refuses a duplicate tier', function () {
    expect(fn () => TierRules::parse([
        ['tier' => 'bronze', 'min_points' => '0'],
        ['tier' => 'bronze', 'min_points' => '100'],
    ]))->toThrow(CustomersRefused::class);
});

it('refuses a duplicate min_points', function () {
    expect(fn () => TierRules::parse([
        ['tier' => 'bronze', 'min_points' => '0'],
        ['tier' => 'silver', 'min_points' => '0'],
    ]))->toThrow(CustomersRefused::class);
});

it('refuses a negative min_points', function () {
    expect(fn () => TierRules::parse([
        ['tier' => 'bronze', 'min_points' => '-1'],
    ]))->toThrow(CustomersRefused::class);
});

it('refuses a missing key', function () {
    expect(fn () => TierRules::parse([
        ['tier' => 'bronze'],
    ]))->toThrow(CustomersRefused::class);
});

it('refuses a non-list', function () {
    expect(fn () => TierRules::parse(['tier' => 'bronze', 'min_points' => '0']))->toThrow(CustomersRefused::class);
});

it('resolves the tier at each boundary', function () {
    $rules = TierRules::parse(tierRulesFixture());

    expect(TierRules::tierFor($rules, '99.9999'))->toBe('bronze')
        ->and(TierRules::tierFor($rules, '100'))->toBe('silver')
        ->and(TierRules::tierFor($rules, '499.9999'))->toBe('silver')
        ->and(TierRules::tierFor($rules, '500'))->toBe('gold');
});

it('resolves the same tier regardless of input order', function () {
    $rules = TierRules::parse([
        ['tier' => 'gold', 'min_points' => '500'],
        ['tier' => 'bronze', 'min_points' => '0'],
        ['tier' => 'silver', 'min_points' => '100'],
    ]);

    expect(TierRules::tierFor($rules, '250'))->toBe('silver');
});

it('gives null below the lowest threshold', function () {
    $rules = TierRules::parse([
        ['tier' => 'silver', 'min_points' => '100'],
        ['tier' => 'gold', 'min_points' => '500'],
    ]);

    expect(TierRules::tierFor($rules, '50'))->toBeNull();
});

it('gives null when the rules are null', function () {
    expect(TierRules::tierFor(null, '50'))->toBeNull();
});

it('picks the highest qualifying tier, never the lowest (M9)', function () {
    $rules = TierRules::parse(tierRulesFixture());

    expect(TierRules::tierFor($rules, '1000'))->toBe('gold');
});

it('compares thresholds numerically, never as strings (M10)', function () {
    $rules = TierRules::parse([
        ['tier' => 'low', 'min_points' => '9'],
        ['tier' => 'high', 'min_points' => '100'],
    ]);

    // A naive string compare would read "100" < "9" (lexicographic), so a
    // balance of 100 would resolve to "low" instead of "high".
    expect(TierRules::tierFor($rules, '100'))->toBe('high');
});
