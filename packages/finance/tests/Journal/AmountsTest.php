<?php

namespace Flexipos\Finance\Tests\Journal;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Finance\Journal\Amounts;
use RuntimeException;
use UnexpectedValueException;

/*
|--------------------------------------------------------------------------
| Exact decimal money (T-033 plan step B3; AC-17 to AC-22)
|--------------------------------------------------------------------------
|
| Pure datasets. The balance cases are the ones a binary floating-point sum
| gets wrong: 0.1 + 0.2 is not 0.3 there, and it must be here.
*/

function amountsRefusal(callable $run): PostingRefused
{
    try {
        $run();
    } catch (PostingRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The amount was accepted but should have been refused.');
}

it('AC-22: parses an amount and formats it at four decimals', function (string $submitted, string $stored) {
    expect(Amounts::format(Amounts::parse($submitted, 'f')))->toBe($stored);
})->with([
    'zero' => ['0', '0.0000'],
    'zero with places' => ['0.00', '0.0000'],
    'one place' => ['12.5', '12.5000'],
    'two places' => ['12.50', '12.5000'],
    'four places' => ['0.0001', '0.0001'],
    'a whole number' => ['7', '7.0000'],
    'the largest value' => ['99999999999999.9999', '99999999999999.9999'],
    'fourteen integer digits' => ['10000000000000', '10000000000000.0000'],
]);

it('AC-17: refuses an amount that is not a string', function (mixed $value) {
    $refused = amountsRefusal(fn () => Amounts::parse($value, 'lines.0.debit'));

    expect($refused->reason)->toBe(PostingRefusal::InvalidAmount)
        ->and($refused->field)->toBe('lines.0.debit');
})->with([
    'an integer' => [1],
    'a float' => [1.0],
    'a float fraction' => [0.5],
    'true' => [true],
    'false' => [false],
    'null' => [null],
    'an array' => [['1']],
]);

it('AC-18: refuses a string outside the amount grammar', function (string $value) {
    expect(amountsRefusal(fn () => Amounts::parse($value, 'f'))->reason)->toBe(PostingRefusal::InvalidAmount);
})->with([
    'empty' => [''],
    'negative' => ['-1'],
    'negative zero' => ['-0'],
    'plus sign' => ['+5'],
    'exponent' => ['1e3'],
    'thousands separator' => ['1,000.00'],
    'leading space' => [' 5'],
    'trailing space' => ['5 '],
    'trailing newline, the dollar trap' => ["5\n"],
    'leading zero' => ['05'],
    'no integer part' => ['.5'],
    'no decimals after the point' => ['5.'],
    'five decimal places, never rounded' => ['1.00001'],
    'fifteen integer digits' => ['100000000000000'],
    'an Arabic-Indic digit' => ['٣'],
    'a null byte' => ["5\0"],
    'a comma decimal' => ['1,5'],
    'hexadecimal' => ['0x10'],
]);

it('AC-19: reads a line into four-decimal strings', function (string $debit, string $credit, array $expected) {
    expect(Amounts::line($debit, $credit, 0))->toBe($expected);
})->with([
    'a debit' => ['10', '0', ['10.0000', '0.0000']],
    'a credit' => ['0', '2.5', ['0.0000', '2.5000']],
    'zero spelled with places' => ['0.00', '0.0001', ['0.0000', '0.0001']],
]);

it('AC-19: refuses a line with both sides positive, naming the line', function () {
    $refused = amountsRefusal(fn () => Amounts::line('0.0001', '1', 3));

    expect($refused->reason)->toBe(PostingRefusal::LineBothSides)
        ->and($refused->field)->toBe('lines.3');
});

it('AC-19: refuses a line with both sides zero in any spelling', function (string $debit, string $credit) {
    $refused = amountsRefusal(fn () => Amounts::line($debit, $credit, 1));

    expect($refused->reason)->toBe(PostingRefusal::LineZero)
        ->and($refused->field)->toBe('lines.1');
})->with([
    'zero and zero' => ['0', '0'],
    'places' => ['0.00', '0.0000'],
    'mixed' => ['0.0', '0'],
]);

it('reads the debit before the credit, so the first wrong field is reported', function () {
    $refused = amountsRefusal(fn () => Amounts::line('x', 'y', 2));

    expect($refused->field)->toBe('lines.2.debit');
});

it('AC-20: balances 0.1 + 0.2 against 0.3 exactly', function () {
    expect(Amounts::balance(['0.1000', '0.2000'], ['0.3000']))->toBe(['0.3000', '0.3000']);
});

it('AC-20: refuses a difference of 0.0001 and carries both totals', function () {
    $refused = amountsRefusal(fn () => Amounts::balance(['0.1000', '0.2000'], ['0.3001']));

    expect($refused->reason)->toBe(PostingRefusal::EntryUnbalanced)
        ->and($refused->context)->toBe(['total_debit' => '0.3000', 'total_credit' => '0.3001']);
});

it('AC-20: sums many small amounts without drift', function () {
    $tenth = array_fill(0, 10, '0.1000');

    expect(Amounts::balance($tenth, ['1.0000']))->toBe(['1.0000', '1.0000']);
});

it('AC-21: accepts a total exactly at the column maximum', function () {
    expect(Amounts::balance(['99999999999999.9999'], ['99999999999999.9999']))
        ->toBe(['99999999999999.9999', '99999999999999.9999']);
});

it('AC-21: accepts a total made of many lines that adds up to exactly the maximum', function () {
    $half = ['49999999999999.9999', '50000000000000.0000'];

    expect(Amounts::balance($half, ['99999999999999.9999']))->toBe(['99999999999999.9999', '99999999999999.9999']);
});

it('AC-21: refuses 200 lines each at the largest single amount', function () {
    $side = array_fill(0, 200, Amounts::MAX);

    $refused = amountsRefusal(fn () => Amounts::balance($side, $side));

    expect($refused->reason)->toBe(PostingRefusal::TotalOutOfRange);
});

it('AC-21: refuses a balanced total one unit in the last place over the maximum', function () {
    $refused = amountsRefusal(fn () => Amounts::balance(['99999999999999.9999', '0.0001'], ['100000000000000.0000']));

    expect($refused->reason)->toBe(PostingRefusal::TotalOutOfRange);
});

it('AC-20: reports an imbalance ahead of a total that is also out of range', function () {
    $refused = amountsRefusal(fn () => Amounts::balance(['99999999999999.9999', '1.0000'], ['1.0000']));

    expect($refused->reason)->toBe(PostingRefusal::EntryUnbalanced);
});

it('AC-22: reads stored values back as four-decimal strings', function (mixed $stored, string $expected) {
    expect(Amounts::fromStored($stored))->toBe($expected);
})->with([
    'a string' => ['12.5', '12.5000'],
    'a four-place string' => ['12.5000', '12.5000'],
    'an integer' => [7, '7.0000'],
    'zero' => ['0', '0.0000'],
    'the stand-in handing a float back' => [12.5, '12.5000'],
    'a float with places' => [0.3, '0.3000'],
    'a float that is a whole number' => [10.0, '10.0000'],
    'the largest value as a string' => ['99999999999999.9999', '99999999999999.9999'],
    'a 15-significant-digit float (R-1)' => [12345678901.2345, '12345678901.2345'],
]);

it('R-1: reads a 15-significant-digit float without the precision ini dropping digits', function () {
    $previous = ini_set('precision', '14');

    try {
        expect(Amounts::fromStored(12345678901.2345))->toBe('12345678901.2345')
            ->and(Amounts::fromStored(99999999999.9999))->toBe('99999999999.9999');
    } finally {
        ini_set('precision', (string) $previous);
    }
});

it('refuses to read a stored value that is not a number or needs adjusting', function () {
    expect(fn () => Amounts::fromStored(null))->toThrow(UnexpectedValueException::class)
        ->and(fn () => Amounts::fromStored(['1']))->toThrow(UnexpectedValueException::class)
        ->and(fn () => Amounts::fromStored(true))->toThrow(UnexpectedValueException::class)
        ->and(fn () => Amounts::fromStored('1.00001'))->toThrow(RoundingNecessaryException::class);
});

it('keeps the maximum constant equal to the column it guards', function () {
    expect(Amounts::MAX)->toBe('99999999999999.9999')
        ->and(BigDecimal::of(Amounts::MAX)->getScale())->toBe(Amounts::SCALE);
});
