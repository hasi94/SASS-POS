<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Pricing\PricedPurchaseLine;
use Flexipos\Contracts\Pricing\PurchaseLineQuote;
use Flexipos\Pricing\Engine\PurchaseLineCalculator;
use Flexipos\Pricing\Engine\TaxApplies;
use Flexipos\Pricing\Engine\TaxMember;
use Flexipos\Pricing\Tests\Support\Build;

/*
|--------------------------------------------------------------------------
| PurchaseLineCalculator (T-034 step 2, AC-43, D-53a)
|--------------------------------------------------------------------------
|
| Pure: no database, no container. Exact strings throughout. The worked
| example is the spec's: 12.0000 x 3.3333 at 10 % discount under a 10 %
| exclusive group.
*/

/**
 * @param  list<TaxMember>  $members
 */
function plcLine(
    array $members,
    string $quantity = '12.0000',
    string $unitCost = '3.3333',
    string $discountPercent = '10.0000',
    bool $inclusive = false,
    string $baseQuantity = '12.0000',
): PricedPurchaseLine {
    return PurchaseLineCalculator::compute(
        new PurchaseLineQuote($quantity, $unitCost, $discountPercent, Build::id('TG'), $baseQuantity, Build::DATE),
        $members,
        $inclusive,
    );
}

it('AC-43: the exclusive worked example, half-up at every step', function () {
    $line = plcLine([Build::member('VAT', '10.0000')]);

    expect($line->subtotal)->toBe('39.9996')
        ->and($line->discountAmount)->toBe('4.0000')
        ->and($line->taxableAmount)->toBe('35.9996')
        ->and($line->tax->total)->toBe('3.6000')
        ->and($line->tax->net)->toBe('35.9996')
        ->and($line->lineTotal)->toBe('39.5996');
});

it('AC-43: the inclusive twin backs the tax out and totals the taxable amount', function () {
    $line = plcLine([Build::member('VAT', '10.0000')], inclusive: true);

    expect($line->subtotal)->toBe('39.9996')
        ->and($line->discountAmount)->toBe('4.0000')
        ->and($line->taxableAmount)->toBe('35.9996')
        ->and($line->tax->net)->toBe('32.7269')
        ->and($line->tax->total)->toBe('3.2727')
        ->and($line->lineTotal)->toBe('35.9996');
});

it('takes no discount at 0 and the whole subtotal at 100', function () {
    $none = plcLine([Build::member('VAT', '10.0000')], discountPercent: '0.0000');
    $all = plcLine([Build::member('VAT', '10.0000')], discountPercent: '100.0000');

    expect($none->discountAmount)->toBe('0.0000')
        ->and($none->taxableAmount)->toBe('39.9996')
        ->and($all->discountAmount)->toBe('39.9996')
        ->and($all->taxableAmount)->toBe('0.0000')
        ->and($all->tax->total)->toBe('0.0000')
        ->and($all->lineTotal)->toBe('0.0000');
});

it('rounds a discount tie half-up: half of 0.0005 is 0.0003, not 0.0002', function () {
    $line = plcLine([], '1.0000', '0.0005', '50.0000');

    expect($line->subtotal)->toBe('0.0005')
        ->and($line->discountAmount)->toBe('0.0003')
        ->and($line->taxableAmount)->toBe('0.0002');
});

it('a zero unit cost prices to zeros', function () {
    $line = plcLine([Build::member('VAT', '10.0000')], unitCost: '0.0000');

    expect($line->subtotal)->toBe('0.0000')
        ->and($line->discountAmount)->toBe('0.0000')
        ->and($line->taxableAmount)->toBe('0.0000')
        ->and($line->tax->total)->toBe('0.0000')
        ->and($line->lineTotal)->toBe('0.0000');
});

it('a group with only a sale-only member taxes nothing and does not refuse', function () {
    $line = plcLine([Build::member('SO', '10.0000', appliesTo: TaxApplies::Sale)]);

    expect($line->tax->lines)->toBe([])
        ->and($line->tax->total)->toBe('0.0000')
        ->and($line->lineTotal)->toBe('35.9996');
});

it('a purchase-only member applies and a sale-only member beside it does not', function () {
    $line = plcLine([
        Build::member('PO', '10.0000', 1, appliesTo: TaxApplies::Purchase),
        Build::member('SO', '5.0000', 2, appliesTo: TaxApplies::Sale),
    ]);

    expect($line->tax->lines)->toHaveCount(1)
        ->and($line->tax->lines[0]->code)->toBe('PO')
        ->and($line->tax->total)->toBe('3.6000')
        ->and($line->lineTotal)->toBe('39.5996');
});

it('a both-sided member applies to a purchase', function () {
    $line = plcLine([Build::member('BO', '10.0000', appliesTo: TaxApplies::Both)]);

    expect($line->tax->total)->toBe('3.6000');
});

it('refuses a subtotal that no longer fits DECIMAL(18,4) as out_of_range', function () {
    expect(Build::reasonOf(fn () => plcLine([], '99999999999999.9999', '2.0000', '0.0000')))->toBe('out_of_range');
});

it('refuses a discount percentage above 100 and a negative one at the DTO', function (string $percent) {
    $reason = Build::reasonOf(fn () => new PurchaseLineQuote('1.0000', '1.0000', $percent, Build::id('TG'), '1.0000', Build::DATE));

    expect($reason)->toBe('invalid_amount');
})->with(['100.0001', '100.0100', '101.0000', '99999.0000', '-1.0000', '-0.0001']);

it('accepts 100.0000 and 0 at the DTO and canonicalises the percentage', function () {
    $top = new PurchaseLineQuote('1', '1', '100', Build::id('TG'), '1', Build::DATE);
    $zero = new PurchaseLineQuote('1', '1', '0', Build::id('TG'), '1', Build::DATE);

    expect($top->discountPercent)->toBe('100.0000')
        ->and($zero->discountPercent)->toBe('0.0000')
        ->and($top->quantity)->toBe('1.0000');
});

it('line total always equals taxable plus tax for an exclusive group, over awkward amounts', function (string $quantity, string $cost, string $percent) {
    $line = plcLine([Build::member('VAT', '7.5000')], $quantity, $cost, $percent);

    expect((string) BigDecimal::of($line->taxableAmount)->plus($line->tax->total))->toBe($line->lineTotal)
        ->and((string) BigDecimal::of($line->subtotal)->minus($line->discountAmount))->toBe($line->taxableAmount);
})->with([
    ['1.0000', '0.0001', '33.3333'],
    ['0.3333', '9.9999', '12.3456'],
    ['1234.5678', '87.6543', '0.0100'],
    ['5.0000', '5.0000', '99.9999'],
]);

it('line total equals taxable for an inclusive group, over awkward amounts', function (string $quantity, string $cost, string $percent) {
    $line = plcLine([Build::member('VAT', '7.5000')], $quantity, $cost, $percent, true);

    expect($line->lineTotal)->toBe($line->taxableAmount);
})->with([
    ['1.0000', '10.0000', '33.3333'],
    ['0.3333', '9.9999', '12.3456'],
    ['1234.5678', '87.6543', '0.0100'],
]);
