<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\TaxBreakdown;
use Flexipos\Contracts\Pricing\TaxContext;
use Flexipos\Pricing\Engine\TaxApplies;
use Flexipos\Pricing\Engine\TaxCalculator;
use Flexipos\Pricing\Engine\TaxMember;
use Flexipos\Pricing\Engine\TaxType;
use Flexipos\Pricing\Tests\Support\Build;

/*
|--------------------------------------------------------------------------
| TaxCalculator (T-029 step 7, AC-9, AC-11, D-15)
|--------------------------------------------------------------------------
|
| Exact strings throughout. The fixtures replay the same rules for
| FP-432; these datasets add the properties a fixture cannot state, such as
| "net plus total is the gross" over awkward amounts.
*/

/**
 * @param  list<TaxMember>  $members
 */
function taxCalc(array $members, string $amount, bool $inclusive = false, string $quantity = '1.0000', TaxContext $context = TaxContext::Sale, string $date = Build::DATE): TaxBreakdown
{
    return TaxCalculator::compute($members, $amount, $quantity, $date, $context, $inclusive);
}

/**
 * @return list<array{string, string, string}> code, base, amount per line
 */
function taxCalcLines(TaxBreakdown $breakdown): array
{
    return array_map(static fn ($line): array => [$line->code, $line->base, $line->amount], $breakdown->lines);
}

it('adds one exclusive percentage on top: 100.0000 at 15 % is 15.0000, total 115', function () {
    $breakdown = taxCalc([Build::member('VAT')], '100.0000');

    expect($breakdown->net)->toBe('100.0000')
        ->and($breakdown->total)->toBe('15.0000')
        ->and($breakdown->isInclusive)->toBeFalse()
        ->and(taxCalcLines($breakdown))->toBe([['VAT', '100.0000', '15.0000']])
        ->and((string) BigDecimal::of($breakdown->net)->plus($breakdown->total))->toBe('115.0000');
});

it('charges two non-compound members on the same plain base', function () {
    $breakdown = taxCalc([Build::member('A', '5.0000', 1), Build::member('B', '10.0000', 2)], '100.0000');

    expect(taxCalcLines($breakdown))->toBe([['A', '100.0000', '5.0000'], ['B', '100.0000', '10.0000']])
        ->and($breakdown->total)->toBe('15.0000');
});

it('charges a compound member on the base plus every amount before it', function () {
    $breakdown = taxCalc([Build::member('A', '5.0000', 1), Build::member('B', '10.0000', 2, true)], '100.0000');

    expect(taxCalcLines($breakdown))->toBe([['A', '100.0000', '5.0000'], ['B', '105.0000', '10.5000']])
        ->and($breakdown->total)->toBe('15.5000');
});

it('includes a preceding compound amount in a second compound base (115.5, not 105)', function () {
    $breakdown = taxCalc([
        Build::member('A', '5.0000', 1),
        Build::member('B', '10.0000', 2, true),
        Build::member('C', '2.0000', 3, true),
    ], '100.0000');

    expect(taxCalcLines($breakdown))->toBe([['A', '100.0000', '5.0000'], ['B', '105.0000', '10.5000'], ['C', '115.5000', '2.3100']])
        ->and($breakdown->total)->toBe('17.8100');
});

it('includes a preceding non-compound amount only for a member that compounds', function () {
    // The non-compound member after a compound one never sees the compound amount.
    $breakdown = taxCalc([
        Build::member('A', '10.0000', 1),
        Build::member('B', '10.0000', 2, true),
        Build::member('C', '5.0000', 3),
    ], '100.0000');

    expect(taxCalcLines($breakdown))->toBe([['A', '100.0000', '10.0000'], ['B', '110.0000', '11.0000'], ['C', '100.0000', '5.0000']]);
});

it('rounds each tax amount half-up once', function (string $rate, string $amount, string $expected) {
    $breakdown = taxCalc([Build::member('VAT', $rate)], $amount);

    expect($breakdown->lines[0]->amount)->toBe($expected)
        ->and($breakdown->total)->toBe($expected);
})->with([
    '33.3333 at 15 % is 4.999995' => ['15.0000', '33.3333', '5.0000'],
    'an exact .00005 goes up' => ['0.5000', '0.0100', '0.0001'],
    'a four-decimal rate is multiplied exactly, not via a rounded 0.1235' => ['12.3456', '100.0000', '12.3456'],
    'a tiny amount rounds to zero' => ['15.0000', '0.0001', '0.0000'],
    'zero rate' => ['0.0000', '100.0000', '0.0000'],
]);

it('backs the tax out of an inclusive gross: 115.0000 is net 100 and tax 15', function () {
    $breakdown = taxCalc([Build::member('VAT')], '115.0000', true);

    expect($breakdown->isInclusive)->toBeTrue()
        ->and($breakdown->net)->toBe('100.0000')
        ->and($breakdown->total)->toBe('15.0000');
});

it('lets the residue fix the sum: 9.9900 is net 8.6870 and tax 1.3030 although 15 % of the net rounds to 1.3031', function () {
    $breakdown = taxCalc([Build::member('VAT')], '9.9900', true);

    expect($breakdown->net)->toBe('8.6870')
        ->and($breakdown->total)->toBe('1.3030')
        ->and((string) BigDecimal::of($breakdown->net)->plus($breakdown->total))->toBe('9.9900');
});

/**
 * @return array<string, array{list<TaxMember>, string}>
 */
function taxCalcInclusiveShapes(): array
{
    return [
        'two compounds' => [[Build::member('A', '5.0000', 1, true), Build::member('B', '10.0000', 2, true)], '1.0000'],
        'non-compound then compound' => [[Build::member('A', '7.5000', 1), Build::member('B', '12.5000', 2, true)], '1.0000'],
        'compound then non-compound' => [[Build::member('A', '15.0000', 1, true), Build::member('B', '5.0000', 2)], '1.0000'],
        'three percentages, mixed' => [[Build::member('A', '8.2500', 1), Build::member('B', '2.5000', 2, true), Build::member('C', '1.7500', 3, true)], '1.0000'],
        'a fixed amount before a compound' => [[Build::member('F', '0.5000', 1, false, TaxType::Fixed), Build::member('B', '15.0000', 2, true)], '2.0000'],
        'a fixed amount after a percentage' => [[Build::member('A', '15.0000', 1), Build::member('F', '0.2500', 2, false, TaxType::Fixed)], '4.0000'],
    ];
}

it('makes net plus total equal the gross exactly, over twenty awkward grosses and every shape', function (array $members, string $quantity) {
    $grosses = [
        '0.0100', '0.0199', '0.3333', '0.9900', '1.0000', '2.5000', '4.9950', '9.9900', '12.3456', '19.9950',
        '33.3333', '99.9999', '100.0001', '179.0000', '1234.5678', '1750.5052', '3193.9072', '7954.2917',
        '55555.5555', '88888888.8888',
    ];

    expect($grosses)->toHaveCount(20);

    $accepted = 0;

    foreach ($grosses as $gross) {
        try {
            $breakdown = taxCalc($members, $gross, true, $quantity);
        } catch (PricingRefused $refused) {
            // Only a gross too small to cover a fixed tax (and the tax on it) may refuse.
            expect($refused->reason->value)->toBe('inclusive_amount_too_small');
            expect(BigDecimal::of($gross)->isLessThan('2.0000'))->toBeTrue();

            continue;
        }

        $accepted++;
        $sum = BigDecimal::zero();

        foreach ($breakdown->lines as $line) {
            $sum = $sum->plus($line->amount);
        }

        expect((string) BigDecimal::of($breakdown->net)->plus($breakdown->total))->toBe($gross, "gross {$gross}")
            ->and((string) $sum)->toBe($breakdown->total, "lines sum for {$gross}");
    }

    expect($accepted)->toBeGreaterThanOrEqual(15);
})->with(taxCalcInclusiveShapes());

it('puts the residue on the last percentage member and on no other', function () {
    $members = [Build::member('A', '5.0000', 1, true), Build::member('B', '10.0000', 2, true)];
    $breakdown = taxCalc($members, '7954.2917', true);

    // Forward, every member rounds its own amount; only the last may differ.
    $first = $breakdown->lines[0];
    $forward = BigDecimal::of($first->base)->multipliedBy($first->rate)->dividedBy(100, 4, RoundingMode::HalfUp);

    expect($breakdown->net)->toBe('6886.8326')
        ->and((string) $forward)->toBe($first->amount)
        ->and($breakdown->lines[1]->amount)->toBe('723.1175');
});

it('charges a fixed amount per stock unit on the base quantity: 0.5000 x 24 is 12.0000', function () {
    $breakdown = taxCalc([Build::member('F', '0.5000', 1, false, TaxType::Fixed)], '179.0000', false, '24.0000');

    expect(taxCalcLines($breakdown))->toBe([['F', '179.0000', '12.0000']])
        ->and($breakdown->lines[0]->type)->toBe('fixed')
        ->and($breakdown->total)->toBe('12.0000');
});

it('takes the fixed amount off an inclusive gross first, and refuses a gross below it', function () {
    $members = [Build::member('VAT'), Build::member('F', '0.5000', 2, false, TaxType::Fixed)];
    $breakdown = taxCalc($members, '179.0000', true, '24.0000');

    expect($breakdown->net)->toBe('145.2174')
        ->and($breakdown->total)->toBe('33.7826')
        ->and(Build::reasonOf(fn () => taxCalc($members, '11.9999', true, '24.0000')))->toBe('inclusive_amount_too_small')
        ->and(taxCalc($members, '12.0000', true, '24.0000')->net)->toBe('0.0000');
});

it('skips a member whose applies_to excludes the context, and keeps one that includes it', function () {
    $members = [
        Build::member('PUR', '10.0000', 1, false, TaxType::Percentage, TaxApplies::Purchase),
        Build::member('SAL', '2.0000', 2, false, TaxType::Percentage, TaxApplies::Sale),
        Build::member('BOTH', '5.0000', 3, false, TaxType::Percentage, TaxApplies::Both),
    ];

    expect(array_column(taxCalcLines(taxCalc($members, '100.0000')), 0))->toBe(['SAL', 'BOTH'])
        ->and(array_column(taxCalcLines(taxCalc($members, '100.0000', false, '1.0000', TaxContext::Purchase)), 0))->toBe(['PUR', 'BOTH']);
});

it('skips an inactive and a soft-deleted member before looking for their rate', function () {
    $members = [
        Build::member('LIVE'),
        Build::member('OFF', '0.0000', 2, false, TaxType::Percentage, TaxApplies::Both, false, false, []),
        Build::member('GONE', '0.0000', 3, false, TaxType::Percentage, TaxApplies::Both, true, true, []),
    ];

    expect(array_column(taxCalcLines(taxCalc($members, '100.0000')), 0))->toBe(['LIVE']);
});

it('gives an empty group zero tax with the amount untouched, exclusive and inclusive (AC-9)', function (bool $inclusive) {
    $breakdown = taxCalc([], '100.0000', $inclusive);

    expect($breakdown->net)->toBe('100.0000')
        ->and($breakdown->lines)->toBe([])
        ->and($breakdown->total)->toBe('0.0000')
        ->and($breakdown->isInclusive)->toBe($inclusive);
})->with([[false], [true]]);

it('gives a group whose every member is filtered out the same zero tax', function () {
    $members = [Build::member('PUR', '10.0000', 1, false, TaxType::Percentage, TaxApplies::Purchase)];

    expect(taxCalc($members, '100.0000')->total)->toBe('0.0000');
});

it('refuses a member that applies but has no rate on the date, even in a group that is otherwise fine', function () {
    $members = [Build::member('VAT'), Build::member('NEW', '1.0000', 2, false, TaxType::Percentage, TaxApplies::Both, true, false, [Build::rate('1.0000', '2030-01-01')])];

    expect(Build::reasonOf(fn () => taxCalc($members, '100.0000')))->toBe('no_effective_rate');
});

it('orders by sequence, not by the order the members arrive in', function () {
    $a = Build::member('A', '5.0000', 1);
    $b = Build::member('B', '10.0000', 2, true);

    expect(taxCalcLines(taxCalc([$b, $a], '200.0000')))->toBe(taxCalcLines(taxCalc([$a, $b], '200.0000')))
        ->and(taxCalcLines(taxCalc([$b, $a], '200.0000')))->toBe([['A', '200.0000', '10.0000'], ['B', '210.0000', '21.0000']]);
});

it('uses the rate in force on the document date', function () {
    $member = Build::member('VAT', '15.0000', 1, false, TaxType::Percentage, TaxApplies::Both, true, false, [
        Build::rate('15.0000', '2020-01-01', '2025-01-01'),
        Build::rate('18.0000', '2025-01-01'),
    ]);

    expect(taxCalc([$member], '100.0000', false, '1.0000', TaxContext::Sale, '2024-12-31')->total)->toBe('15.0000')
        ->and(taxCalc([$member], '100.0000', false, '1.0000', TaxContext::Sale, '2025-01-01')->total)->toBe('18.0000');
});

it('refuses a malformed input by name', function (string $amount, string $quantity) {
    expect(Build::reasonOf(fn () => taxCalc([Build::member('VAT')], $amount, false, $quantity)))->toBe('invalid_amount');
})->with([
    'a fifth decimal' => ['1.00005', '1.0000'],
    'a negative amount' => ['-1.0000', '1.0000'],
    'an exponent' => ['1e2', '1.0000'],
    'a negative quantity' => ['1.0000', '-1.0000'],
    'blank' => ['', '1.0000'],
]);

it('refuses a result beyond DECIMAL(18,4) by name', function () {
    expect(Build::reasonOf(fn () => taxCalc([Build::member('VAT', '200.0000')], '99999999999999.9999')))->toBe('out_of_range');
});

it('refuses a fixed tax that claims to compound, and an unknown sequence', function () {
    expect(fn () => Build::member('F', '1.0000', 1, true, TaxType::Fixed))->toThrow(\InvalidArgumentException::class, 'cannot compound')
        ->and(fn () => Build::member('F', '1.0000', 300))->toThrow(\InvalidArgumentException::class, '[sequence]');
});
