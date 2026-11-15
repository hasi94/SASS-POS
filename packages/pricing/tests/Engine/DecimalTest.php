<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Brick\Math\BigDecimal;
use Flexipos\Pricing\Engine\Decimal;
use Flexipos\Pricing\Tests\Support\Build;

/*
|--------------------------------------------------------------------------
| Decimal (T-029 step 6, plan Ruling 3)
|--------------------------------------------------------------------------
|
| The parse rows of Ruling 3.1, half-up rounding, the range edge and the
| canonical output. Every row is an exact string.
*/

it('accepts a plain non-negative decimal and returns the canonical four-decimal string', function (string $input, string $expected) {
    expect((string) Decimal::money($input, 'amount'))->toBe($expected);
})->with([
    'one decimal' => ['7.5', '7.5000'],
    'whole number' => ['7', '7.0000'],
    'four decimals' => ['0.0001', '0.0001'],
    'leading zeros are folded' => ['007.50', '7.5000'],
    'the largest value' => ['99999999999999.9999', '99999999999999.9999'],
]);

it('refuses everything else as invalid_amount', function (mixed $input) {
    expect(Build::reasonOf(fn () => Decimal::money($input, 'amount')))->toBe('invalid_amount');
})->with([
    'a float' => [7.5],
    'an int' => [7],
    'null' => [null],
    'an exponent' => ['1e3'],
    'a fifth decimal' => ['7.12345'],
    'blank' => [''],
    'padded with a space' => [' 7'],
    'negative' => ['-1.0000'],
    'negative zero' => ['-0'],
    'a comma' => ['1,5'],
    'a trailing newline' => ["7\n"],
    'fifteen integer digits' => ['100000000000000.0000'],
    'a bare dot' => ['.'],
    'no integer part' => ['.5'],
]);

it('parses a rate to five integer and four fraction digits and a factor to at least one', function () {
    expect((string) Decimal::rate('15', 'rate'))->toBe('15.0000')
        ->and((string) Decimal::rate('99999.9999', 'rate'))->toBe('99999.9999')
        ->and(Build::reasonOf(fn () => Decimal::rate('100000', 'rate')))->toBe('invalid_amount')
        ->and((string) Decimal::factor('24', 'factor'))->toBe('24.000000')
        ->and((string) Decimal::factor('1', 'factor'))->toBe('1.000000')
        ->and(Build::reasonOf(fn () => Decimal::factor('0.999999', 'factor')))->toBe('invalid_amount')
        ->and(Build::reasonOf(fn () => Decimal::factor('0', 'factor')))->toBe('invalid_amount');
});

it('rounds half up at the fifth decimal and never half-even', function (string $input, string $expected) {
    expect((string) Decimal::round(BigDecimal::of($input)))->toBe($expected);
})->with([
    'exactly .00005 goes up' => ['0.00005', '0.0001'],
    '.00004 stays' => ['0.00004', '0.0000'],
    '.00015 goes up (half-even would too)' => ['0.00015', '0.0002'],
    '.00025 goes up (half-even would not)' => ['0.00025', '0.0003'],
    '2.00005 goes up' => ['2.00005', '2.0001'],
]);

it('never prints a negative zero', function () {
    $tiny = Decimal::round(BigDecimal::of('-0.00001'));

    expect(Decimal::text($tiny))->toBe('0.0000')
        ->and(Decimal::text(BigDecimal::zero()))->toBe('0.0000');
});

it('refuses a negative output by name instead of printing it', function () {
    expect(Build::reasonOf(fn () => Decimal::text(BigDecimal::of('-0.0001'))))->toBe('out_of_range');
});

it('refuses an unrounded value reaching an output', function () {
    expect(Build::reasonOf(fn () => Decimal::text(BigDecimal::of('1.00005'))))->toBe('invalid_amount');
});

it('holds the range edge: the largest DECIMAL(18,4) passes and one step over is out_of_range', function () {
    expect((string) Decimal::round(BigDecimal::of('99999999999999.9999')))->toBe('99999999999999.9999')
        ->and(Decimal::text(BigDecimal::of('99999999999999.9999')))->toBe('99999999999999.9999')
        ->and(Build::reasonOf(fn () => Decimal::round(BigDecimal::of('100000000000000.0000'))))->toBe('out_of_range')
        ->and(Build::reasonOf(fn () => Decimal::round(BigDecimal::of('99999999999999.99995'))))->toBe('out_of_range')
        ->and(Build::reasonOf(fn () => Decimal::text(BigDecimal::of('100000000000000.0000'))))->toBe('out_of_range');
});

it('multiplies a percentage exactly and rounds once', function (string $amount, string $percent, string $expected) {
    expect((string) Decimal::percentOf(BigDecimal::of($amount), BigDecimal::of($percent)))->toBe($expected);
})->with([
    '33.3333 x 15% = 4.999995' => ['33.3333', '15.0000', '5.0000'],
    '0.01 x 0.5% = 0.00005' => ['0.0100', '0.5000', '0.0001'],
    '100 x 12.3456% is exact' => ['100.0000', '12.3456', '12.3456'],
    '0 of anything' => ['0.0000', '15.0000', '0.0000'],
    '100% of an amount' => ['179.0000', '100.0000', '179.0000'],
]);

it('refuses a percentage result beyond the range', function () {
    expect(Build::reasonOf(fn () => Decimal::percentOf(BigDecimal::of('99999999999999.9999'), BigDecimal::of('200.0000'))))->toBe('out_of_range');
});
