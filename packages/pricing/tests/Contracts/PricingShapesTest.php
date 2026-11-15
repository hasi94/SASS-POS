<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Contracts;

use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Contracts\Pricing\BillQuote;
use Flexipos\Contracts\Pricing\LineQuote;
use Flexipos\Contracts\Pricing\PriceBasis;
use Flexipos\Contracts\Pricing\PriceSource;
use Flexipos\Contracts\Pricing\Shape;
use Flexipos\Contracts\Pricing\TaxBreakdown;
use Flexipos\Contracts\Pricing\TaxContext;
use Flexipos\Contracts\Pricing\TaxLine;
use Flexipos\Contracts\Pricing\TaxQuote;
use Flexipos\Pricing\Tests\Support\Build;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| DTO shapes (T-029 step 5, plan Ruling 2)
|--------------------------------------------------------------------------
|
| Shape only, the `UnitSnapshot` discipline: a malformed id, date, instant or
| currency throws InvalidArgumentException naming the field; a malformed
| amount is a named `invalid_amount` refusal.
*/

function shapesSnapshot(): UnitSnapshot
{
    return new UnitSnapshot(Build::id('UNITCASE'), 'case', '1.0000', '24.000000', '24.0000', FactorSource::ProductConversion);
}

/**
 * @param  array<string, mixed>  $override
 */
function shapesLineQuote(array $override = []): LineQuote
{
    $arguments = [
        'productId' => Build::id('PRODA'),
        'unit' => shapesSnapshot(),
        'at' => '2025-06-15T09:30:00.000Z',
        'documentDate' => '2025-06-15',
        'currency' => 'USD',
        'customerId' => null,
        'customerGroupId' => null,
        'branchId' => null,
        'discountId' => null,
        ...$override,
    ];

    return new LineQuote(...$arguments);
}

it('accepts a well-formed line quote, folding ids to upper case', function () {
    $quote = shapesLineQuote(['productId' => strtolower(Build::id('PRODA')), 'customerId' => Build::id('CUSTA'), 'discountId' => Build::id('DISC1')]);

    expect($quote->productId)->toBe(Build::id('PRODA'))
        ->and($quote->unit->unitFactor)->toBe('24.000000')
        ->and($quote->at)->toBe('2025-06-15T09:30:00.000Z')
        ->and($quote->currency)->toBe('USD')
        ->and($quote->customerId)->toBe(Build::id('CUSTA'))
        ->and($quote->branchId)->toBeNull();
});

it('refuses a hand-built line quote that is malformed, naming the field', function (array $override, string $field) {
    expect(fn () => shapesLineQuote($override))->toThrow(InvalidArgumentException::class, "[{$field}]");
})->with([
    'a malformed ULID' => [['productId' => 'NOT-A-ULID'], 'productId'],
    'a malformed optional ULID' => [['customerId' => '01SHORT'], 'customerId'],
    'a month 13' => [['documentDate' => '2026-13-01'], 'documentDate'],
    'a non-date' => [['documentDate' => '2025/06/15'], 'documentDate'],
    'an instant without the Z' => [['at' => '2025-06-15T09:30:00.000'], 'at'],
    'an instant without milliseconds' => [['at' => '2025-06-15T09:30:00Z'], 'at'],
    'an instant at hour 24' => [['at' => '2025-06-15T24:00:00.000Z'], 'at'],
    'a two-letter currency' => [['currency' => 'US'], 'currency'],
    'a lower-case currency' => [['currency' => 'usd'], 'currency'],
]);

it('refuses a tax quote with a 5-decimal amount as invalid_amount, and a malformed date by field', function () {
    $ok = new TaxQuote(Build::id('TAXGROUPA'), '100', '24.0000', '2025-06-01', TaxContext::Sale);

    expect($ok->amount)->toBe('100.0000')
        ->and(Build::reasonOf(fn () => new TaxQuote(Build::id('TAXGROUPA'), '100.00005', '1.0000', '2025-06-01', TaxContext::Sale)))->toBe('invalid_amount')
        ->and(Build::reasonOf(fn () => new TaxQuote(Build::id('TAXGROUPA'), '1.0000', '-1.0000', '2025-06-01', TaxContext::Sale)))->toBe('invalid_amount')
        ->and(fn () => new TaxQuote(Build::id('TAXGROUPA'), '1.0000', '1.0000', '2026-13-01', TaxContext::Sale))->toThrow(InvalidArgumentException::class, '[documentDate]');
});

it('refuses a bill quote with a malformed discounted subtotal by name', function () {
    $ok = new BillQuote(Build::id('BILL1'), '2025-06-15T09:30:00.000Z', ['100', '50.5']);

    expect($ok->discountedSubtotals)->toBe(['100.0000', '50.5000'])
        ->and(Build::reasonOf(fn () => new BillQuote(Build::id('BILL1'), '2025-06-15T09:30:00.000Z', ['1.00005'])))->toBe('invalid_amount')
        ->and(fn () => new BillQuote(Build::id('BILL1'), 'yesterday', []))->toThrow(InvalidArgumentException::class, '[at]');
});

it('keeps the price source honest about where a price came from', function () {
    $fromList = new PriceSource(1, Build::id('LISTA'), PriceBasis::UnitMatched);
    $fromProduct = new PriceSource(6, null, PriceBasis::Product);

    expect($fromList->toArray())->toBe(['step' => 1, 'price_list_id' => Build::id('LISTA'), 'basis' => 'unit_matched'])
        ->and($fromProduct->toArray())->toBe(['step' => 6, 'price_list_id' => null, 'basis' => 'product'])
        ->and(fn () => new PriceSource(0, null, PriceBasis::Product))->toThrow(InvalidArgumentException::class, '[step]')
        ->and(fn () => new PriceSource(7, null, PriceBasis::Product))->toThrow(InvalidArgumentException::class, '[step]')
        ->and(fn () => new PriceSource(2, null, PriceBasis::Derived))->toThrow(InvalidArgumentException::class, 'price list')
        ->and(fn () => new PriceSource(3, Build::id('LISTA'), PriceBasis::Product))->toThrow(InvalidArgumentException::class, 'price list')
        ->and(fn () => new PriceSource(5, Build::id('LISTA'), PriceBasis::Product))->toThrow(InvalidArgumentException::class, 'product')
        ->and(fn () => new PriceSource(6, null, PriceBasis::Derived))->toThrow(InvalidArgumentException::class, 'product');
});

it('serialises a tax breakdown to exactly the sale_items.tax_breakdown shape', function () {
    $line = new TaxLine(Build::id('TAXVAT'), 'VAT', null, 'percentage', false, '15', '100', '15');
    $breakdown = new TaxBreakdown(false, '100', [$line], '15');

    expect($breakdown->toArray())->toBe([
        'is_inclusive' => false,
        'net' => '100.0000',
        'lines' => [[
            'tax_id' => Build::id('TAXVAT'),
            'code' => 'VAT',
            'tax_code' => null,
            'type' => 'percentage',
            'is_compound' => false,
            'rate' => '15.0000',
            'base' => '100.0000',
            'amount' => '15.0000',
        ]],
        'total' => '15.0000',
    ]);
});

it('canonicalises amounts to four decimals and refuses everything but a plain string', function () {
    expect(Shape::money('0', 'x'))->toBe('0.0000')
        ->and(Shape::money('012.5', 'x'))->toBe('12.5000')
        ->and(Shape::rate('99999.9999', 'x'))->toBe('99999.9999')
        ->and(Shape::factor('24', 'x'))->toBe('24.000000')
        ->and(Shape::MAX_AMOUNT)->toBe('99999999999999.9999')
        ->and(Build::reasonOf(fn () => Shape::money(7.5, 'x')))->toBe('invalid_amount')
        ->and(Build::reasonOf(fn () => Shape::money('7.5 ', 'x')))->toBe('invalid_amount')
        ->and(Build::reasonOf(fn () => Shape::factor('0.5', 'x')))->toBe('invalid_amount');
});
