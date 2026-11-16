<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Documents;

use Flexipos\Purchasing\Documents\OrderRollup;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/*
|--------------------------------------------------------------------------
| T-034 step 7 - OrderRollup (AC-45, AC-47)
|--------------------------------------------------------------------------
*/

/**
 * @return array<string, string>
 */
function puTotalsLine(string $subtotal, string $discount, string $tax, string $lineTotal): array
{
    return ['subtotal' => $subtotal, 'discount_amount' => $discount, 'tax_amount' => $tax, 'line_total' => $lineTotal];
}

it('sums line columns into header totals and adds shipping to the total only (AC-45)', function () {
    $totals = OrderRollup::totals([
        puTotalsLine('39.9996', '4.0000', '3.6000', '39.5996'),
        puTotalsLine('10.0000', '0.0000', '0.0000', '10.0000'),
    ], '2.5000');

    expect($totals)->toBe([
        'subtotal' => '49.9996',
        'discount_total' => '4.0000',
        'tax_total' => '3.6000',
        'total' => '52.0996',
    ]);
});

it('gives every total 0.0000 plus shipping for an order with no lines (AC-45)', function () {
    expect(OrderRollup::totals([], '2.5000'))->toBe(['subtotal' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'total' => '2.5000'])
        ->and(OrderRollup::totals([], '0.0000')['total'])->toBe('0.0000');
});

it('reads the stand-in s int and float spellings of the stored columns', function () {
    $totals = OrderRollup::totals([['subtotal' => 10, 'discount_amount' => 0.5, 'tax_amount' => 1, 'line_total' => 10.5]], '0');

    expect($totals)->toBe(['subtotal' => '10.0000', 'discount_total' => '0.5000', 'tax_total' => '1.0000', 'total' => '10.5000']);
});

it('refuses a total that cannot fit DECIMAL(18,4) as out_of_range, whichever total it is (AC-47)', function (array $line, string $shipping, string $name) {
    try {
        OrderRollup::totals([$line], $shipping);
        $refused = null;
    } catch (PurchasingRefused $refused) {
    }

    expect($refused)->toBeInstanceOf(PurchasingRefused::class)
        ->and($refused->code())->toBe('out_of_range')
        ->and($refused->getMessage())->toContain("order {$name} ");
})->with([
    'the total, by shipping' => [puTotalsLine('1.0000', '0.0000', '0.0000', '99999999999999.9999'), '0.0001', 'total'],
    'the total, by lines alone' => [puTotalsLine('0.0000', '0.0000', '0.0000', '100000000000000.0000'), '0.0000', 'total'],
]);

it('refuses overflow across lines that each fit (AC-47)', function () {
    $line = puTotalsLine('60000000000000.0000', '0.0000', '0.0000', '0.0000');

    try {
        OrderRollup::totals([$line, $line], '0.0000');
        $refused = null;
    } catch (PurchasingRefused $refused) {
    }

    expect($refused)->toBeInstanceOf(PurchasingRefused::class)->and($refused->code())->toBe('out_of_range')->and($refused->getMessage())->toContain('order subtotal ');
});
