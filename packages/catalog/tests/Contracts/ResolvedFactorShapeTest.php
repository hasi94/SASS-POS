<?php

declare(strict_types=1);

use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\ResolvedFactor;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| ResolvedFactor: shape only (T-027 plan step 5)
|--------------------------------------------------------------------------
*/

function resolvedFactorUlid(): string
{
    return (string) Str::ulid();
}

function resolvedFactorMixed(mixed $value): mixed
{
    return $value;
}

it('accepts a well-formed instance', function () {
    $factor = new ResolvedFactor(resolvedFactorUlid(), resolvedFactorUlid(), resolvedFactorUlid(), '24.000000', FactorSource::UnitChain);

    expect($factor->productId)->toBe(strtoupper($factor->productId))
        ->and($factor->factor)->toBe('24.000000')
        ->and($factor->source)->toBe(FactorSource::UnitChain)
        ->and($factor->conversionId)->toBeNull();
});

it('refuses a float factor', function () {
    expect(fn () => new ResolvedFactor(resolvedFactorUlid(), resolvedFactorUlid(), resolvedFactorUlid(), resolvedFactorMixed(24.0), FactorSource::UnitChain))
        ->toThrow(TypeError::class);
});

it('refuses a factor with 7 decimals', function () {
    expect(fn () => new ResolvedFactor(resolvedFactorUlid(), resolvedFactorUlid(), resolvedFactorUlid(), '24.0000001', FactorSource::UnitChain))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a factor below 1', function () {
    expect(fn () => new ResolvedFactor(resolvedFactorUlid(), resolvedFactorUlid(), resolvedFactorUlid(), '0.999999', FactorSource::UnitChain))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a malformed ULID', function () {
    expect(fn () => new ResolvedFactor('not-a-ulid', resolvedFactorUlid(), resolvedFactorUlid(), '24.000000', FactorSource::UnitChain))
        ->toThrow(InvalidArgumentException::class);
});

it('folds a supplied conversionId to upper case', function () {
    $conversionId = strtolower(resolvedFactorUlid());

    $factor = new ResolvedFactor(resolvedFactorUlid(), resolvedFactorUlid(), resolvedFactorUlid(), '1.000000', FactorSource::StockUnit, $conversionId);

    expect($factor->conversionId)->toBe(strtoupper($conversionId));
});
