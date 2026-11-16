<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Pricing\PricedLine;
use Flexipos\Pricing\Engine\DiscountCalculator;
use Flexipos\Pricing\Engine\DiscountRule;
use Flexipos\Pricing\Engine\DiscountScope;
use Flexipos\Pricing\Engine\DiscountType;
use Flexipos\Pricing\Engine\LadderInput;
use Flexipos\Pricing\Engine\LineCalculator;
use Flexipos\Pricing\Engine\LineInput;
use Flexipos\Pricing\Engine\ProductFacts;
use Flexipos\Pricing\Engine\ScopeType;
use Flexipos\Pricing\Engine\TaxApplies;
use Flexipos\Pricing\Engine\TaxMember;
use Flexipos\Pricing\Engine\TaxType;
use Flexipos\Pricing\Tests\Support\Build;

/*
|--------------------------------------------------------------------------
| LineCalculator (T-029 step 9, AC-22; D-4, D-12)
|--------------------------------------------------------------------------
|
| The order is fixed: price, subtotal on the LINE-UNIT quantity, discount,
| tax on the discounted amount, total. These tests pin each step with numbers
| that differ if the step is moved or fed the wrong quantity.
*/

/**
 * @param  list<TaxMember>|null  $members
 * @param  list<DiscountRule>  $rules
 */
function lineOf(LadderInput $ladder, string $quantity, ?array $members = null, bool $inclusive = false, ?string $discountId = null, array $rules = [], bool $allowDiscount = true): PricedLine
{
    return LineCalculator::compute(new LineInput(
        $ladder,
        $quantity,
        new ProductFacts(Build::id('PRODA'), null, null, $allowDiscount),
        Build::id('TAXGROUPA'),
        $inclusive,
        $members ?? [Build::member('VAT')],
        Build::DATE,
        $discountId === null ? null : Build::id($discountId),
        $rules,
    ));
}

function lineCaseLadder(): LadderInput
{
    return Build::ladder(Build::id('UNITCASE'), '24.000000', '24.0000', [
        Build::list('LC', [Build::item(Build::id('UNITCASE'), '1.0000', '179.0000')], assignments: [Build::assignment(ScopeType::Customer, Build::id('CUSTA'))]),
    ], customer: Build::id('CUSTA'));
}

it('prices one case at 179 as subtotal 179, never 179 x the base quantity 24 (D-12)', function () {
    $line = lineOf(lineCaseLadder(), '1.0000');

    expect($line->subtotal)->toBe('179.0000')
        ->and($line->unitPrice)->toBe('179.0000')
        ->and($line->tax->total)->toBe('26.8500')
        ->and($line->lineTotal)->toBe('205.8500');
});

it('composes the plan example: 179, 10 % off, 15 % on the discounted amount', function () {
    $line = lineOf(lineCaseLadder(), '1.0000', null, false, 'DISC1', [Build::discount()]);

    expect($line->listPrice)->toBe('179.0000')
        ->and($line->subtotal)->toBe('179.0000')
        ->and($line->discountAmount)->toBe('17.9000')
        ->and($line->discountedSubtotal)->toBe('161.1000')
        // 15 % of 161.1, not of 179 (26.85): the discount comes before the tax.
        ->and($line->tax->total)->toBe('24.1650')
        ->and($line->lineTotal)->toBe('185.2650')
        ->and($line->discountId)->toBe(Build::id('DISC1'));
});

it('multiplies the unit price by the line-unit quantity: three cases derived at 180 is 540', function () {
    $line = lineOf(Build::ladder(Build::id('UNITCASE'), '24.000000', '72.0000', price: '7.5000'), '3.0000');

    expect($line->unitPrice)->toBe('180.0000')
        ->and($line->subtotal)->toBe('540.0000')
        ->and($line->lineTotal)->toBe('621.0000');
});

it('is a plain product for three singles, where the base and line quantities coincide', function () {
    $line = lineOf(Build::ladder(Build::id('UNITEACH'), '1.000000', '3.0000', price: '7.5000'), '3.0000');

    expect($line->subtotal)->toBe('22.5000')
        ->and($line->tax->total)->toBe('3.3750')
        ->and($line->lineTotal)->toBe('25.8750')
        ->and($line->discountAmount)->toBe('0.0000')
        ->and($line->discountId)->toBeNull();
});

it('does not add the tax of an inclusive group on top: the total is the discounted subtotal', function () {
    $line = lineOf(lineCaseLadder(), '1.0000', null, true, 'DISC1', [Build::discount()]);

    expect($line->discountedSubtotal)->toBe('161.1000')
        ->and($line->tax->isInclusive)->toBeTrue()
        ->and($line->tax->net)->toBe('140.0870')
        ->and($line->tax->total)->toBe('21.0130')
        ->and($line->lineTotal)->toBe('161.1000')
        ->and((string) BigDecimal::of($line->tax->net)->plus($line->tax->total))->toBe($line->lineTotal);
});

it('takes a fixed discount before compound taxes', function () {
    $line = lineOf(
        Build::ladder(Build::id('UNITEACH'), '1.000000', '2.0000', price: '100.0000'),
        '2.0000',
        [Build::member('A', '5.0000', 1), Build::member('B', '10.0000', 2, true)],
        false,
        'DISC1',
        [Build::discount(type: DiscountType::Fixed, value: '20.0000')],
    );

    expect($line->subtotal)->toBe('200.0000')
        ->and($line->discountedSubtotal)->toBe('180.0000')
        ->and($line->tax->total)->toBe('27.9000')
        ->and($line->lineTotal)->toBe('207.9000');
});

it('charges a fixed tax on the base quantity, while the subtotal uses the line quantity', function () {
    $line = lineOf(
        Build::ladder(Build::id('UNITCASE'), '24.000000', '48.0000', branch: Build::id('BRANCHA'), branchPrice: '7.5000'),
        '2.0000',
        [Build::member('VAT'), Build::member('F', '0.5000', 2, false, TaxType::Fixed)],
    );

    expect($line->source->step)->toBe(5)
        ->and($line->subtotal)->toBe('360.0000')
        // 0.5 per stock unit x base 48 = 24, not x line quantity 2.
        ->and($line->tax->lines[1]->amount)->toBe('24.0000')
        ->and($line->lineTotal)->toBe('438.0000');
});

it('judges min_amount on the line subtotal, not the unit price', function () {
    $rule = [Build::discount(minAmount: '200.0000')];

    expect(lineOf(lineCaseLadder(), '2.0000', null, false, 'DISC1', $rule)->discountAmount)->toBe('35.8000')
        ->and(Build::reasonOf(fn () => lineOf(lineCaseLadder(), '1.0000', null, false, 'DISC1', $rule)))->toBe('below_min_amount');
});

it('applies a price-list item percent before the discount', function () {
    $ladder = Build::ladder(Build::id('UNITCASE'), '24.000000', '24.0000', [
        Build::list('LC', [Build::item(Build::id('UNITCASE'), '1.0000', '179.0000', '10.0000')], assignments: [Build::assignment(ScopeType::Customer, Build::id('CUSTA'))]),
    ], customer: Build::id('CUSTA'));

    $line = lineOf($ladder, '1.0000', null, false, 'DISC1', [Build::discount()]);

    expect($line->listPrice)->toBe('179.0000')
        ->and($line->listDiscountPercent)->toBe('10.0000')
        ->and($line->unitPrice)->toBe('161.1000')
        ->and($line->subtotal)->toBe('161.1000')
        ->and($line->discountAmount)->toBe('16.1100')
        ->and($line->lineTotal)->toBe('166.7385');
});

it('refuses a discount that is not among the candidates, and one that is not allowed on the product', function () {
    expect(Build::reasonOf(fn () => lineOf(lineCaseLadder(), '1.0000', null, false, 'MISSING', [Build::discount()])))->toBe('unknown_discount')
        ->and(Build::reasonOf(fn () => lineOf(lineCaseLadder(), '1.0000', null, false, 'DISC1', [Build::discount()], false)))->toBe('discount_not_allowed');
});

it('refuses before pricing the discount when no price resolves, and before tax when a discount refuses', function () {
    // No price source at all: the price refusal wins over a discount that would also refuse.
    expect(Build::reasonOf(fn () => lineOf(Build::ladder(Build::id('UNITEACH'), '1.000000', '1.0000'), '1.0000', null, false, 'DISC1', [Build::discount(active: false)])))->toBe('no_price_resolved')
        // A discount refusal wins over a tax with no rate.
        ->and(Build::reasonOf(fn () => lineOf(lineCaseLadder(), '1.0000', [Build::member('VAT', '1.0000', 1, false, TaxType::Percentage, TaxApplies::Both, true, false, [Build::rate('1.0000', '2030-01-01')])], false, 'DISC1', [Build::discount(active: false)])))->toBe('inactive');
});

it('refuses a negative or malformed quantity by name', function () {
    expect(Build::reasonOf(fn () => lineOf(lineCaseLadder(), '-1.0000')))->toBe('invalid_amount')
        ->and(Build::reasonOf(fn () => lineOf(lineCaseLadder(), '1.00005')))->toBe('invalid_amount');
});

it('refuses a subtotal beyond DECIMAL(18,4) by name', function () {
    expect(Build::reasonOf(fn () => lineOf(Build::ladder(Build::id('UNITEACH'), '1.000000', '1.0000', price: '99999999999999.9999'), '2.0000')))->toBe('out_of_range');
});

it('shapes the priced line for sale_items: every amount a canonical four-decimal string', function () {
    $array = lineOf(lineCaseLadder(), '1.0000', null, false, 'DISC1', [Build::discount()])->toArray();

    expect(array_keys($array))->toBe([
        'list_price', 'list_discount_percent', 'unit_price', 'source', 'subtotal', 'discount_amount',
        'discount_id', 'discounted_subtotal', 'tax_group_id', 'tax', 'line_total',
    ])
        ->and(array_keys($array['tax']))->toBe(['is_inclusive', 'net', 'lines', 'total'])
        ->and(array_keys($array['tax']['lines'][0]))->toBe(['tax_id', 'code', 'tax_code', 'type', 'is_compound', 'rate', 'base', 'amount'])
        ->and(array_keys($array['source']))->toBe(['step', 'price_list_id', 'basis'])
        ->and($array['line_total'])->toMatch('/^\d+\.\d{4}$/');
});

it('feeds a bill discount the pre-tax discounted subtotals, not the line totals (D-19)', function () {
    $one = lineOf(lineCaseLadder(), '1.0000', null, false, 'DISC1', [Build::discount()]);
    $two = lineOf(Build::ladder(Build::id('UNITEACH'), '1.000000', '2.0000', price: '100.0000'), '2.0000');
    $rule = Build::discount('BILL1', DiscountType::Percentage, '5.0000', DiscountScope::Bill);

    $preTax = DiscountCalculator::forBill($rule, Build::AT, [$one->discountedSubtotal, $two->discountedSubtotal]);
    $withTax = DiscountCalculator::forBill($rule, Build::AT, [$one->lineTotal, $two->lineTotal]);

    // 161.1 + 200 = 361.1; with tax 185.265 + 230 = 415.265. Only the first is the bill base.
    expect($preTax->base)->toBe('361.1000')
        ->and($preTax->amount)->toBe('18.0550')
        ->and($withTax->base)->toBe('415.2650')
        ->and($preTax->base)->not->toBe($withTax->base);
});
