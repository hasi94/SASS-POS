<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Sales;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Sales\SaleRollup;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 9 - SaleRollup (AC-8, AC-51; R-11)
|--------------------------------------------------------------------------
|
| The header is a plain sum of the lines' own stored columns: `total` is the sum
| of `line_total`, `tax_total` is informational for an inclusive sale, and zero
| lines give zeros. Lines are seeded directly.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/sales-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    salesEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @return array{sale: string, catalogue: array{product: string, unit: string, taxGroup: string}}
 */
function rollupSale(): array
{
    $branch = SalesSeed::branch();

    return [
        'sale' => SalesSeed::sale($branch, SalesSeed::location($branch), SalesSeed::employee('cashier')),
        'catalogue' => SalesSeed::catalogue(),
    ];
}

it('sums an exclusive sale: subtotal 179, discount 17.9, tax 24.165, total 185.265', function () {
    $w = rollupSale();

    SalesSeed::line($w['sale'], $w['catalogue'], 1, ['subtotal' => '100.0000', 'discount_amount' => '10.0000', 'tax_amount' => '13.5000', 'line_total' => '103.5000']);
    SalesSeed::line($w['sale'], $w['catalogue'], 2, ['subtotal' => '50.0000', 'discount_amount' => '5.0000', 'tax_amount' => '6.7500', 'line_total' => '51.7500']);
    SalesSeed::line($w['sale'], $w['catalogue'], 3, ['subtotal' => '29.0000', 'discount_amount' => '2.9000', 'tax_amount' => '3.9150', 'line_total' => '30.0150']);

    $totals = SaleRollup::of(SalesSeed::connection(), $w['sale']);

    expect($totals->subtotal)->toBe('179.0000')
        ->and($totals->discountTotal)->toBe('17.9000')
        ->and($totals->taxTotal)->toBe('24.1650')
        ->and($totals->total)->toBe('185.2650')
        // The identity that holds for an all-exclusive sale.
        ->and($totals->toColumns())->toBe([
            'subtotal' => '179.0000',
            'discount_total' => '17.9000',
            'tax_total' => '24.1650',
            'total' => '185.2650',
        ]);
});

it('takes the total from the lines, so tax_total is informational for an inclusive sale', function () {
    $w = rollupSale();

    // An inclusive line: the subtotal is the gross, the tax is inside it, and
    // the line total is the gross less the discount.
    SalesSeed::line($w['sale'], $w['catalogue'], 1, ['subtotal' => '115.0000', 'discount_amount' => '15.0000', 'tax_amount' => '13.0435', 'line_total' => '100.0000']);

    $totals = SaleRollup::of(SalesSeed::connection(), $w['sale']);

    expect($totals->total)->toBe('100.0000')
        ->and($totals->taxTotal)->toBe('13.0435')
        // subtotal - discount + tax is NOT the total here, and nothing pretends it is.
        ->and($totals->total)->not->toBe('113.0435');
});

it('gives all zeros for a sale with no lines', function () {
    $w = rollupSale();

    $totals = SaleRollup::of(SalesSeed::connection(), $w['sale']);

    expect($totals->toColumns())->toBe([
        'subtotal' => '0.0000',
        'discount_total' => '0.0000',
        'tax_total' => '0.0000',
        'total' => '0.0000',
    ]);
});

it('sums only the named sale', function () {
    $w = rollupSale();
    $other = SalesSeed::sale(SalesSeed::row('sales', $w['sale'])['branch_id'], SalesSeed::row('sales', $w['sale'])['location_id'], SalesSeed::row('sales', $w['sale'])['employee_id']);

    SalesSeed::line($w['sale'], $w['catalogue'], 1, ['line_total' => '10.0000']);
    SalesSeed::line($other, $w['catalogue'], 1, ['line_total' => '99.0000']);

    expect(SaleRollup::of(SalesSeed::connection(), $w['sale'])->total)->toBe('10.0000');
});

it('refuses out_of_range when a sum no longer fits DECIMAL(18,4)', function () {
    $w = rollupSale();

    SalesSeed::line($w['sale'], $w['catalogue'], 1, ['line_total' => '99999999999999.9999']);
    SalesSeed::line($w['sale'], $w['catalogue'], 2, ['line_total' => '0.0001']);

    SalesRefusals::expect(SalesRefusal::OutOfRange, fn () => SaleRollup::of(SalesSeed::connection(), $w['sale']));
});
