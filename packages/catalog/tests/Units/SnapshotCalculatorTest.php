<?php

declare(strict_types=1);

use Flexipos\Catalog\Units\SnapshotCalculator;
use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\ResolvedFactor;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| SnapshotCalculator (T-027 plan step 6, Ruling 4)
|--------------------------------------------------------------------------
|
| Pure. Every row is the exact string expected, because the rounding order
| and mode are part of the contract text (FP-237's TypeScript twin).
*/

function snapshotFactor(string $factor, FactorSource $source = FactorSource::UnitChain): ResolvedFactor
{
    return new ResolvedFactor((string) Str::ulid(), (string) Str::ulid(), (string) Str::ulid(), $factor, $source);
}

function snapshotMixed(mixed $value): mixed
{
    return $value;
}

it('rounds the entered quantity to the line precision first, then computes base', function (
    int $lineUnitPrecision,
    int|string $quantity,
    string $factor,
    string $expectedQuantity,
    string $expectedBase,
) {
    $snapshot = SnapshotCalculator::compute(snapshotFactor($factor), (string) Str::ulid(), 'u', $lineUnitPrecision, 4, $quantity);

    expect($snapshot->quantity)->toBe($expectedQuantity)
        ->and($snapshot->baseQuantity)->toBe($expectedBase);
})->with([
    'precision 0, qty 2.5, factor 24 (not 60)' => [0, '2.5', '24.000000', '3.0000', '72.0000'],
    'precision 2, qty 1.005, factor 3 (not 3.0150)' => [2, '1.005', '3.000000', '1.0100', '3.0300'],
]);

it('rounds HALF_UP, never HALF_EVEN, including on a negative quantity', function (int|string $quantity, string $expected) {
    $snapshot = SnapshotCalculator::compute(snapshotFactor('1.000000'), (string) Str::ulid(), 'u', 0, 4, $quantity);

    expect($snapshot->quantity)->toBe($expected);
})->with([
    '2.5 up to 3, never 2' => ['2.5', '3.0000'],
    '-2.5 to -3' => ['-2.5', '-3.0000'],
]);

it('rounds the base to 4 decimals exactly once', function () {
    $snapshot = SnapshotCalculator::compute(snapshotFactor('3.000001'), (string) Str::ulid(), 'u', 4, 4, '0.3333');

    expect($snapshot->baseQuantity)->toBe('0.9999');
});

it('rejects a fractional base quantity on a precision-0 stock unit (Q-11)', function () {
    try {
        SnapshotCalculator::compute(snapshotFactor('1.500000'), (string) Str::ulid(), 'u', 4, 0, '1');
        expect(false)->toBeTrue('the fractional base quantity was accepted');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::FractionalBaseQuantity);
    }
});

it('accepts the same fractional base when the stock unit is not precision 0 (control)', function () {
    $snapshot = SnapshotCalculator::compute(snapshotFactor('1.500000'), (string) Str::ulid(), 'u', 4, 1, '1');

    expect($snapshot->baseQuantity)->toBe('1.5000');
});

it('refuses a float quantity', function () {
    expect(fn () => SnapshotCalculator::compute(snapshotFactor('1.000000'), (string) Str::ulid(), 'u', 4, 4, snapshotMixed(2.5)))
        ->toThrow(TypeError::class);
});

it('refuses a non-decimal string quantity', function () {
    try {
        SnapshotCalculator::compute(snapshotFactor('1.000000'), (string) Str::ulid(), 'u', 4, 4, '1e3');
        expect(false)->toBeTrue('the malformed quantity was accepted');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::InvalidQuantity);
    }
});

it('refuses a quantity over 14 integer digits', function () {
    try {
        SnapshotCalculator::compute(snapshotFactor('1.000000'), (string) Str::ulid(), 'u', 0, 4, '100000000000000');
        expect(false)->toBeTrue('the overflowing quantity was accepted');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::QuantityOutOfRange);
    }
});

it('carries unit_code through', function () {
    $snapshot = SnapshotCalculator::compute(snapshotFactor('1.000000'), (string) Str::ulid(), 'case', 0, 4, '1');

    expect($snapshot->unitCode)->toBe('case');
});
