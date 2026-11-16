<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Contracts\Pricing\LineQuote;
use Flexipos\Contracts\Pricing\PricingEngine;
use Flexipos\Contracts\Pricing\TaxContext;
use Flexipos\Contracts\Pricing\TaxQuote;
use Flexipos\Pricing\Tests\Support\Build;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA: the adapter and an inactive tax group (T-029 AC-11 step 0, AC-30.8, D-33)
|--------------------------------------------------------------------------
|
| An inactive group governs NEW assignments (AC-3, AC-10), not a quote already
| in flight, so both `computeTax` and `quoteLine` compute it normally. An
| unknown id is the only refusal. The shipped suite proves the unknown half
| (DatabasePricingEngineTest); nothing there proves the inactive half, which is
| the half a well-meaning "is_active" filter in the adapter would break.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * An inactive group holding one 15 % tax, optionally inclusive.
 */
function qaInactiveGroup(string $code, bool $inclusive = false): string
{
    $tax = PricingSeed::tax(PricingSeed::authority('QA'.$code), 'QAVAT'.$code);

    PricingSeed::rate($tax, '15.0000', '2020-01-01');

    $group = PricingSeed::group('QAG'.$code, ['is_active' => false, 'is_inclusive' => $inclusive]);

    PricingSeed::member($group, $tax, 1);

    return $group;
}

it('AC-30.8: computeTax computes an inactive exclusive group normally, with no refusal', function () {
    $group = qaInactiveGroup('EX');

    $breakdown = app(PricingEngine::class)->computeTax(new TaxQuote($group, '100.0000', '1.0000', Build::DATE, TaxContext::Sale));

    expect($breakdown->isInclusive)->toBeFalse()
        ->and($breakdown->net)->toBe('100.0000')
        ->and($breakdown->total)->toBe('15.0000')
        ->and($breakdown->lines)->toHaveCount(1);
});

it('AC-30.8: computeTax computes an inactive inclusive group normally too', function () {
    $group = qaInactiveGroup('IN', true);

    $breakdown = app(PricingEngine::class)->computeTax(new TaxQuote($group, '115.0000', '1.0000', Build::DATE, TaxContext::Sale));

    expect($breakdown->isInclusive)->toBeTrue()
        ->and($breakdown->net)->toBe('100.0000')
        ->and($breakdown->total)->toBe('15.0000');
});

it('AC-30.8: quoteLine prices a product whose tax group has since been deactivated (a quote in flight)', function () {
    $group = qaInactiveGroup('QL');
    $unit = PricingSeed::unit('UQL');
    $product = PricingSeed::product('QL', $unit, ['tax_group_id' => $group, 'selling_price' => '10.0000']);

    $priced = app(PricingEngine::class)->quoteLine(new LineQuote(
        $product,
        new UnitSnapshot($unit, 'each', '2.0000', '1.000000', '2.0000', FactorSource::StockUnit),
        Build::AT,
        Build::DATE,
        'USD',
    ));

    // 10.0000 x 2 = 20.0000; 15 % of it is 3.0000, added on top.
    expect($priced->subtotal)->toBe('20.0000')
        ->and($priced->tax->total)->toBe('3.0000')
        ->and($priced->lineTotal)->toBe('23.0000');
});

it('AC-30.8 control: an id that is no tax group at all is still refused unknown_tax_group', function () {
    $quote = new TaxQuote(Build::id('QANOSUCH'), '100.0000', '1.0000', Build::DATE, TaxContext::Sale);

    expect(Build::reasonOf(fn () => app(PricingEngine::class)->computeTax($quote)))->toBe('unknown_tax_group');
});
