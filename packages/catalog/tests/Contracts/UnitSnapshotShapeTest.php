<?php

declare(strict_types=1);

use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| UnitSnapshot: shape only (T-027 plan step 5)
|--------------------------------------------------------------------------
|
| This DTO never re-derives or re-rounds anything; it only checks that the
| four strings have the shapes `docs/database.md` §3.1/§3.2 require. The one
| documented construction path is `UnitResolver::snapshot()`
| (`SnapshotCalculatorTest` proves the arithmetic).
*/

function catalogUlid(): string
{
    return (string) Str::ulid();
}

/**
 * Hands a value back as `mixed`, so a deliberately illegal literal (a float,
 * where the constructor's own type declares `string`) reaches the call as a
 * value PHPStan cannot statically reject.
 */
function catalogMixed(mixed $value): mixed
{
    return $value;
}

it('accepts a well-formed instance', function () {
    $snapshot = new UnitSnapshot(catalogUlid(), 'case', '3.0000', '24.000000', '72.0000', FactorSource::UnitChain);

    expect($snapshot->unitId)->toBe(strtoupper($snapshot->unitId))
        ->and($snapshot->unitCode)->toBe('case')
        ->and($snapshot->quantity)->toBe('3.0000')
        ->and($snapshot->unitFactor)->toBe('24.000000')
        ->and($snapshot->baseQuantity)->toBe('72.0000')
        ->and($snapshot->source)->toBe(FactorSource::UnitChain);
});

it('refuses a float quantity', function () {
    expect(fn () => new UnitSnapshot(catalogUlid(), 'case', catalogMixed(3.0), '24.000000', '72.0000', FactorSource::UnitChain))
        ->toThrow(TypeError::class);
});

it('refuses a quantity with 5 decimals', function () {
    expect(fn () => new UnitSnapshot(catalogUlid(), 'case', '3.00001', '24.000000', '72.0000', FactorSource::UnitChain))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a unitFactor below 1', function () {
    expect(fn () => new UnitSnapshot(catalogUlid(), 'g', '1.0000', '0.999999', '0.9999', FactorSource::UnitChain))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a malformed ULID', function () {
    expect(fn () => new UnitSnapshot('not-a-ulid', 'case', '3.0000', '24.000000', '72.0000', FactorSource::UnitChain))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a baseQuantity with more than 14 integer digits', function () {
    expect(fn () => new UnitSnapshot(catalogUlid(), 'case', '3.0000', '24.000000', '100000000000000.0000', FactorSource::UnitChain))
        ->toThrow(InvalidArgumentException::class);
});
