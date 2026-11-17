<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Support;

use Flexipos\Sales\Support\Money;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| T-030 step 9 - Money (Ruling 3, R-8, AC-7's one multiplication)
|--------------------------------------------------------------------------
|
| Pure: canonical 4-decimal strings, no float, never `-0.0000`, one product
| rounded HALF_UP. The canonicalising of a stored value (an int or a float on
| the SQLite stand-in, a string on MySQL) is R-8's fix, so each shape has a row.
*/

it('canonicalises an int, a float and a string to four decimals', function (int|float|string $stored, string $expected) {
    expect(Money::of($stored))->toBe($expected);
})->with([
    'an int' => [7, '7.0000'],
    'a float the stand-in hands back' => [7.5, '7.5000'],
    'a short string' => ['7.5', '7.5000'],
    'a full string' => ['7.5000', '7.5000'],
    'a string with no fraction' => ['10', '10.0000'],
    'a float with float noise' => [0.1 + 0.2, '0.3000'],
    'a negative' => ['-2.5', '-2.5000'],
]);

it('never produces a negative zero', function () {
    expect(Money::of('-0'))->toBe('0.0000')
        ->and(Money::of('-0.0000'))->toBe('0.0000')
        ->and(Money::negate('0.0000'))->toBe('0.0000')
        ->and(Money::subtract('5.0000', '5.0000'))->toBe('0.0000');
});

it('refuses a fifth decimal and anything that is not a plain decimal rather than rounding it', function (string $bad) {
    expect(fn () => Money::of($bad))->toThrow(InvalidArgumentException::class);
})->with(['five decimals' => ['1.00005'], 'an exponent' => ['1e3'], 'blank' => [''], 'text' => ['abc'], 'a plus sign' => ['+1']]);

it('adds, subtracts, negates and sums exactly', function () {
    expect(Money::add('0.1000', '0.2000'))->toBe('0.3000')
        ->and(Money::subtract('45.0000', '50.0000'))->toBe('-5.0000')
        ->and(Money::negate('12.3400'))->toBe('-12.3400')
        ->and(Money::negate('-12.3400'))->toBe('12.3400')
        ->and(Money::abs('-3'))->toBe('3.0000')
        ->and(Money::sum([100, '50.5', 29.25]))->toBe('179.7500')
        ->and(Money::sum([]))->toBe('0.0000');
});

it('compares by value, so 10 and 10.0000 are one number and never a text mismatch (R-8)', function () {
    expect(Money::equals('10', '10.0000'))->toBeTrue()
        ->and(Money::equals('10.0000', '10.0001'))->toBeFalse()
        ->and(Money::compare('2.0000', '10.0000'))->toBe(-1)
        ->and(Money::isZero('0'))->toBeTrue()
        ->and(Money::isPositive('0.0001'))->toBeTrue()
        ->and(Money::isPositive('0'))->toBeFalse()
        ->and(Money::min('3', '2.5'))->toBe('2.5000');
});

it('is the one multiplication: round(7.5000 x 3.0000) = 22.5000', function () {
    expect(Money::product('7.5000', '3.0000'))->toBe('22.5000')
        ->and(Money::product('7.5', '3'))->toBe('22.5000');
});

it('rounds the product HALF_UP at the fifth decimal', function (string $price, string $quantity, string $expected) {
    expect(Money::product($price, $quantity))->toBe($expected);
})->with([
    'exactly half rounds up' => ['0.0001', '0.5000', '0.0001'],
    'just under half rounds down' => ['0.0001', '0.4999', '0.0000'],
    'a half in a larger figure' => ['1.0001', '0.5000', '0.5001'],
    'a half that is not at the end' => ['3.3333', '1.5000', '5.0000'],
]);

it('knows what fits DECIMAL(18,4)', function () {
    expect(Money::fits('99999999999999.9999'))->toBeTrue()
        ->and(Money::fits('-99999999999999.9999'))->toBeTrue()
        ->and(Money::fits('100000000000000.0000'))->toBeFalse();
});
