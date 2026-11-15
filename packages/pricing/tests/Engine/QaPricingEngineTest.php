<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Brick\Math\BigDecimal;
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
| QA: inclusive tax, hand-derived (T-029 AC-11.6, AC-30.6, D-25)
|--------------------------------------------------------------------------
|
| Written by qa, against the spec and not against the implementation. Every
| expected figure below was worked out by hand from the linear relation
| `gross - F = net x (1 + S) + K` (D-25), never read back from the code:
|
|   non-compound 5 % and 10 %: S = 0.15, so 115 / 1.15 = 100 exactly. The
|   superseded wording (the product of each 1 + rate/100) would divide by
|   1.155 and push about half a unit into the residue.
|
| The shipped suite proves `net + total = gross` over awkward grosses, which a
| wrong divisor still satisfies, because the residue absorbs the difference.
| These cases pin the NET itself, which a wrong divisor cannot reproduce.
*/

/**
 * @param  list<TaxMember>  $members
 */
function qaTax(array $members, string $gross, string $quantity = '1.0000'): TaxBreakdown
{
    return TaxCalculator::compute($members, $gross, $quantity, Build::DATE, TaxContext::Sale, true);
}

/**
 * @return list<array{string, string, string}> code, base, amount
 */
function qaLines(TaxBreakdown $breakdown): array
{
    return array_map(static fn ($line): array => [$line->code, $line->base, $line->amount], $breakdown->lines);
}

it('AC-30.6: non-compound 5 % and 10 % on a gross of 115.0000 give net 100.0000, and no residue', function () {
    $breakdown = qaTax([Build::member('A', '5.0000', 1), Build::member('B', '10.0000', 2)], '115.0000');

    expect($breakdown->net)->toBe('100.0000')
        ->and(qaLines($breakdown))->toBe([['A', '100.0000', '5.0000'], ['B', '100.0000', '10.0000']])
        ->and($breakdown->total)->toBe('15.0000');
});

it('AC-30.6: the same two members in the other sequence give the same net and the same amounts', function () {
    $breakdown = qaTax([Build::member('B', '10.0000', 1), Build::member('A', '5.0000', 2)], '115.0000');

    expect($breakdown->net)->toBe('100.0000')
        ->and(qaLines($breakdown))->toBe([['B', '100.0000', '10.0000'], ['A', '100.0000', '5.0000']]);
});

it('AC-30.6: a gross of 230.0000 doubles it exactly (net 200, 10 and 20)', function () {
    $breakdown = qaTax([Build::member('A', '5.0000', 1), Build::member('B', '10.0000', 2)], '230.0000');

    expect($breakdown->net)->toBe('200.0000')
        ->and(qaLines($breakdown))->toBe([['A', '200.0000', '10.0000'], ['B', '200.0000', '20.0000']]);
});

it('AC-30.6: a gross that does not divide evenly rounds the net once, and the last percentage member absorbs the cent', function () {
    // 10 / 1.15 = 8.695652..., so net 8.6957. Forward: 5 % is 0.43479 (0.4348),
    // 10 % is 0.86957 (0.8696); 8.6957 + 0.4348 + 0.8696 = 10.0001, a residue
    // of -0.0001 that goes to the LAST member (B), 0.8695. The product wording
    // would give net 8.6580.
    $breakdown = qaTax([Build::member('A', '5.0000', 1), Build::member('B', '10.0000', 2)], '10.0000');

    expect($breakdown->net)->toBe('8.6957')
        ->and(qaLines($breakdown))->toBe([['A', '8.6957', '0.4348'], ['B', '8.6957', '0.8695']])
        ->and($breakdown->total)->toBe('1.3043');
});

it('D-25: a non-compound 5 % then a compound 10 % on 115.5000 gives net 100 (S = 0.05 + 0.1 x 1.05 = 0.155)', function () {
    $breakdown = qaTax([Build::member('A', '5.0000', 1), Build::member('B', '10.0000', 2, true)], '115.5000');

    expect($breakdown->net)->toBe('100.0000')
        ->and(qaLines($breakdown))->toBe([['A', '100.0000', '5.0000'], ['B', '105.0000', '10.5000']]);
});

it('D-25: a compound 10 % then a non-compound 5 % on 115.0000 gives net 100 (the compound member has nothing before it)', function () {
    $breakdown = qaTax([Build::member('A', '10.0000', 1, true), Build::member('B', '5.0000', 2)], '115.0000');

    expect($breakdown->net)->toBe('100.0000')
        ->and(qaLines($breakdown))->toBe([['A', '100.0000', '10.0000'], ['B', '100.0000', '5.0000']]);
});

it('AC-11.6: a fixed tax that exactly equals the gross leaves net 0.0000 and refuses nothing', function () {
    // 1.0000 per unit on a base quantity of 2 is 2.0000; the compound 10 % is
    // charged on 0 + 2 = 2, so 0.2: R = 2.2 - 2 - 0.2 = 0.
    $members = [Build::member('F', '1.0000', 1, false, TaxType::Fixed), Build::member('C', '10.0000', 2, true)];
    $breakdown = qaTax($members, '2.2000', '2.0000');

    expect($breakdown->net)->toBe('0.0000')
        ->and(qaLines($breakdown))->toBe([['F', '0.0000', '2.0000'], ['C', '2.0000', '0.2000']])
        ->and($breakdown->total)->toBe('2.2000');
});

it('AC-11.6: a gross one step short of the fixed tax and the tax charged on it is inclusive_amount_too_small', function () {
    $members = [Build::member('F', '1.0000', 1, false, TaxType::Fixed), Build::member('C', '10.0000', 2, true)];

    // The fixed taxes alone (2.0000) already exceed 1.9999.
    expect(Build::reasonOf(fn () => qaTax($members, '1.9999', '2.0000')))->toBe('inclusive_amount_too_small')
        // The fixed taxes are covered (2.1 > 2) but the compound tax on them is not: R = -0.1.
        ->and(Build::reasonOf(fn () => qaTax($members, '2.1000', '2.0000')))->toBe('inclusive_amount_too_small');
});

it('AC-11.6: a fixed tax alone is exactly its amount, whatever the gross above it', function () {
    $breakdown = qaTax([Build::member('F', '0.5000', 1, false, TaxType::Fixed)], '20.0000', '24.0000');

    expect($breakdown->net)->toBe('8.0000')
        ->and(qaLines($breakdown))->toBe([['F', '8.0000', '12.0000']]);
});

it('AC-11.6: net plus total is the gross to the last digit, and the net is the hand value, for a spread of non-compound pairs', function (string $a, string $b, string $gross, string $net) {
    $breakdown = qaTax([Build::member('A', $a, 1), Build::member('B', $b, 2)], $gross);

    expect($breakdown->net)->toBe($net)
        ->and((string) BigDecimal::of($breakdown->net)->plus($breakdown->total))->toBe($gross);
})->with([
    '5 and 10 on 115' => ['5.0000', '10.0000', '115.0000', '100.0000'],
    '2 and 3 on 105' => ['2.0000', '3.0000', '105.0000', '100.0000'],
    '7.5 and 2.5 on 110' => ['7.5000', '2.5000', '110.0000', '100.0000'],
    '12.5 and 12.5 on 250' => ['12.5000', '12.5000', '250.0000', '200.0000'],
]);

/*
|--------------------------------------------------------------------------
| QA: the tie-break between two tax members (AC-30.4, D-29)
|--------------------------------------------------------------------------
|
| "Given two lists (or two taxes) that tie on every earlier key, Then the
| order falls to a bytewise comparison of code (tax members: of the tax id)."
| The shipped suite proves the list half end to end; a tax-id tie comparator
| that returns 0 (so rows keep the order they arrived in) passes all of it.
*/

it('AC-30.4: two members on the same sequence order by tax id, whichever order they arrive in', function () {
    $a = Build::member('A', '5.0000', 1);
    $b = Build::member('B', '10.0000', 1);

    foreach ([[$a, $b], [$b, $a]] as $arrival) {
        $breakdown = TaxCalculator::compute($arrival, '100.0000', '1.0000', Build::DATE, TaxContext::Sale, false);

        expect(qaLines($breakdown))->toBe([['A', '100.0000', '5.0000'], ['B', '100.0000', '10.0000']]);
    }
});

it('AC-30.4: a lower sequence still beats a lower tax id (the id is only the tie-break)', function () {
    $first = Build::member('Z', '5.0000', 1);
    $second = Build::member('A', '10.0000', 2);

    $breakdown = TaxCalculator::compute([$second, $first], '100.0000', '1.0000', Build::DATE, TaxContext::Sale, false);

    expect(array_map(static fn ($line): string => $line->code, $breakdown->lines))->toBe(['Z', 'A']);
});

/*
| Round 2 (D-36/D-43): a duplicate effective_from is refused only among members
| that survive the active/deleted filter; a suspended or deleted member's
| duplicate rows never reach the resolver, and the group still prices.
*/
it('ignores duplicate effective_from rows on an inactive or deleted member and refuses them on an active one', function () {
    $dup = [Build::rate('5.0000', '2024-01-01'), Build::rate('6.0000', '2024-01-01')];

    foreach ([[false, false], [false, true]] as [$active, $deleted]) {
        $b = qaTax([Build::member('VAT', '15.0000', 1), Build::member('OLD', '0.0000', 2, false, TaxType::Percentage, TaxApplies::Both, $active, $deleted, $dup)], '115.0000');
        expect($b->net)->toBe('100.0000')->and($b->total)->toBe('15.0000');
    }

    expect(fn () => qaTax([Build::member('VAT', '15.0000', 1, false, TaxType::Percentage, TaxApplies::Both, true, false, $dup)], '115.0000'))
        ->toThrow(PricingRefused::class);
    expect(fn () => qaTax([Build::member('VAT', '15.0000', 1, false, TaxType::Percentage, TaxApplies::Both, true, false, array_reverse($dup))], '115.0000'))
        ->toThrow(PricingRefused::class);
});
