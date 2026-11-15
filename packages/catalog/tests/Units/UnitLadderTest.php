<?php

declare(strict_types=1);

use Flexipos\Catalog\Units\ConversionRow;
use Flexipos\Catalog\Units\Dimension;
use Flexipos\Catalog\Units\UnitCatalogue;
use Flexipos\Catalog\Units\UnitLadder;
use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| UnitLadder (T-027 plan step 7, Ruling 3): one dataset row per ladder path
|--------------------------------------------------------------------------
*/

function ladderId(string $tag): string
{
    return str_pad($tag, 26, 'x');
}

/**
 * each (root, count, p0); case (base each, 24, p0); kg (root, mass, p3);
 * g (base kg, 0.001, p0); lb (base kg, 0.453592); pack3 (base each, 3);
 * pack10 (base each, 10); l (root, volume); dozen (count root, no chain).
 */
function ladderCatalogue(): UnitCatalogue
{
    return new UnitCatalogue([
        ['id' => ladderId('each'), 'code' => 'each', 'dimension' => Dimension::Count, 'precision' => 0, 'baseUnitId' => null, 'factor' => null],
        ['id' => ladderId('case'), 'code' => 'case', 'dimension' => Dimension::Count, 'precision' => 0, 'baseUnitId' => ladderId('each'), 'factor' => '24.000000'],
        ['id' => ladderId('kg'), 'code' => 'kg', 'dimension' => Dimension::Mass, 'precision' => 3, 'baseUnitId' => null, 'factor' => null],
        ['id' => ladderId('g'), 'code' => 'g', 'dimension' => Dimension::Mass, 'precision' => 0, 'baseUnitId' => ladderId('kg'), 'factor' => '0.001000'],
        ['id' => ladderId('lb'), 'code' => 'lb', 'dimension' => Dimension::Mass, 'precision' => 3, 'baseUnitId' => ladderId('kg'), 'factor' => '0.453592'],
        ['id' => ladderId('pack3'), 'code' => 'pack3', 'dimension' => Dimension::Count, 'precision' => 0, 'baseUnitId' => ladderId('each'), 'factor' => '3.000000'],
        ['id' => ladderId('pack10'), 'code' => 'pack10', 'dimension' => Dimension::Count, 'precision' => 0, 'baseUnitId' => ladderId('each'), 'factor' => '10.000000'],
        ['id' => ladderId('l'), 'code' => 'l', 'dimension' => Dimension::Volume, 'precision' => 3, 'baseUnitId' => null, 'factor' => null],
        ['id' => ladderId('dozen'), 'code' => 'dozen', 'dimension' => Dimension::Count, 'precision' => 0, 'baseUnitId' => null, 'factor' => null],
    ]);
}

function ladderCycleCatalogue(): UnitCatalogue
{
    return new UnitCatalogue([
        ['id' => ladderId('each'), 'code' => 'each', 'dimension' => Dimension::Count, 'precision' => 0, 'baseUnitId' => null, 'factor' => null],
        // A and B base each other: a hand-inserted cycle (Ruling 6 forbids
        // this through CreateUnit/UpdateUnit, but the ladder must still
        // not loop if one ever exists).
        ['id' => ladderId('cya'), 'code' => 'cya', 'dimension' => Dimension::Count, 'precision' => 0, 'baseUnitId' => ladderId('cyb'), 'factor' => '2.000000'],
        ['id' => ladderId('cyb'), 'code' => 'cyb', 'dimension' => Dimension::Count, 'precision' => 0, 'baseUnitId' => ladderId('cya'), 'factor' => '2.000000'],
    ]);
}

function expectRefusal(callable $call, UnitRefusal $reason): void
{
    try {
        $call();
        expect(false)->toBeTrue("expected a [{$reason->value}] refusal, got none");
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe($reason);
    }
}

it('resolves through the ladder, one path per step', function (string $from, string $stock, ?ConversionRow $product, ?ConversionRow $global, string $expectedFactor, FactorSource $expectedSource) {
    $resolved = UnitLadder::resolve(ladderCatalogue(), ladderId('prod'), ladderId($from), ladderId($stock), $product, $global);

    expect($resolved->factor)->toBe($expectedFactor)
        ->and($resolved->source)->toBe($expectedSource);
})->with([
    'step 1 beats step 2' => ['case', 'each', new ConversionRow(ladderId('pc'), ladderId('prod'), ladderId('case'), ladderId('each'), '12.000000'), new ConversionRow(ladderId('gc'), null, ladderId('case'), ladderId('each'), '24.000000'), '12.000000', FactorSource::ProductConversion],
    'step 2 alone' => ['case', 'each', null, new ConversionRow(ladderId('gc'), null, ladderId('case'), ladderId('each'), '24.000000'), '24.000000', FactorSource::GlobalConversion],
    'step 3: stock each, line case, no rows' => ['case', 'each', null, null, '24.000000', FactorSource::UnitChain],
    'step 3: stock g, line kg' => ['kg', 'g', null, null, '1000.000000', FactorSource::UnitChain],
    'step 3: stock g, line lb' => ['lb', 'g', null, null, '453.592000', FactorSource::UnitChain],
    'step 3 control: global row wins over an inexact chain' => ['pack10', 'pack3', null, new ConversionRow(ladderId('gc'), null, ladderId('pack10'), ladderId('pack3'), '3.333333'), '3.333333', FactorSource::GlobalConversion],
    'step 4: line equals stock' => ['each', 'each', null, null, '1.000000', FactorSource::StockUnit],
]);

it('refuses a cross-dimension conversion, even with a hand-built global row present (AC-10)', function () {
    $hostileGlobalRow = new ConversionRow(ladderId('gc'), null, ladderId('l'), ladderId('kg'), '1.000000');

    expectRefusal(fn () => UnitLadder::resolve(ladderCatalogue(), ladderId('prod'), ladderId('l'), ladderId('kg'), null, $hostileGlobalRow), UnitRefusal::CrossDimension);
});

it('rejects exhaustion rather than defaulting to 1 (AC-15)', function () {
    expectRefusal(fn () => UnitLadder::resolve(ladderCatalogue(), ladderId('prod'), ladderId('dozen'), ladderId('each'), null, null), UnitRefusal::NoConversionPath);
});

it('refuses an inexact chain quotient rather than rounding it', function () {
    expectRefusal(fn () => UnitLadder::resolve(ladderCatalogue(), ladderId('prod'), ladderId('pack10'), ladderId('pack3'), null, null), UnitRefusal::InexactFactor);
});

it('refuses a resolved factor below 1 (AC-9), from the chain and from a product row', function () {
    expectRefusal(fn () => UnitLadder::resolve(ladderCatalogue(), ladderId('prod'), ladderId('g'), ladderId('kg'), null, null), UnitRefusal::FactorBelowOne);

    $belowOneProductRow = new ConversionRow(ladderId('pc'), ladderId('prod'), ladderId('g'), ladderId('kg'), '0.500000');
    expectRefusal(fn () => UnitLadder::resolve(ladderCatalogue(), ladderId('prod'), ladderId('g'), ladderId('kg'), $belowOneProductRow, null), UnitRefusal::FactorBelowOne);
});

it('falls through a chain cycle to no_conversion_path, never looping', function () {
    expectRefusal(fn () => UnitLadder::resolve(ladderCycleCatalogue(), ladderId('prod'), ladderId('cya'), ladderId('each'), null, null), UnitRefusal::NoConversionPath);
});

it('refuses a factor over DECIMAL(18,6)\'s range', function () {
    $overflow = new ConversionRow(ladderId('gc'), null, ladderId('case'), ladderId('each'), '1000000000000.000000');

    expectRefusal(fn () => UnitLadder::resolve(ladderCatalogue(), ladderId('prod'), ladderId('case'), ladderId('each'), null, $overflow), UnitRefusal::FactorOutOfRange);
});
