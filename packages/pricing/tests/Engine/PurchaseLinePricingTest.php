<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Pricing\PricingEngine;
use Flexipos\Contracts\Pricing\PurchaseLinePricing;
use Flexipos\Contracts\Pricing\PurchaseLineQuote;
use Flexipos\Pricing\Tests\Support\Build;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| PurchaseLinePricing on the database adapter (T-034 step 2, AC-43, D-53a)
|--------------------------------------------------------------------------
|
| The adapter reads the tax group and its members and hands them to the pure
| calculator. These cases prove the reads: an inactive group, a sale-only and a
| purchase-only member, an unknown group, and the single shared instance.
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
 * A group holding one 10 % tax that applies to `$appliesTo`.
 *
 * @param  array<string, mixed>  $group
 */
function plpGroup(string $code, string $appliesTo = 'both', bool $inclusive = false, array $group = []): string
{
    $tax = PricingSeed::tax(PricingSeed::authority('PL'.$code), 'PLVAT'.$code, ['applies_to' => $appliesTo]);

    PricingSeed::rate($tax, '10.0000', '2020-01-01');

    $id = PricingSeed::group('PLG'.$code, array_merge(['is_inclusive' => $inclusive], $group));

    PricingSeed::member($id, $tax, 1);

    return $id;
}

function plpQuote(string $group, string $discountPercent = '10.0000'): PurchaseLineQuote
{
    return new PurchaseLineQuote('12.0000', '3.3333', $discountPercent, $group, '12.0000', Build::DATE);
}

it('AC-43: prices the exclusive worked example through the database adapter', function () {
    $line = app(PurchaseLinePricing::class)->quotePurchaseLine(plpQuote(plpGroup('EX')));

    expect($line->subtotal)->toBe('39.9996')
        ->and($line->discountAmount)->toBe('4.0000')
        ->and($line->taxableAmount)->toBe('35.9996')
        ->and($line->tax->total)->toBe('3.6000')
        ->and($line->lineTotal)->toBe('39.5996');
});

it('AC-43: prices the inclusive twin through the database adapter', function () {
    $line = app(PurchaseLinePricing::class)->quotePurchaseLine(plpQuote(plpGroup('IN', 'both', true)));

    expect($line->tax->isInclusive)->toBeTrue()
        ->and($line->tax->net)->toBe('32.7269')
        ->and($line->tax->total)->toBe('3.2727')
        ->and($line->lineTotal)->toBe('35.9996');
});

it('a full discount leaves a zero line total', function () {
    $line = app(PurchaseLinePricing::class)->quotePurchaseLine(plpQuote(plpGroup('FD'), '100.0000'));

    expect($line->taxableAmount)->toBe('0.0000')
        ->and($line->lineTotal)->toBe('0.0000');
});

it('a sale-only member taxes a purchase at zero, with no refusal', function () {
    $line = app(PurchaseLinePricing::class)->quotePurchaseLine(plpQuote(plpGroup('SO', 'sale')));

    expect($line->tax->lines)->toBe([])
        ->and($line->tax->total)->toBe('0.0000')
        ->and($line->lineTotal)->toBe('35.9996');
});

it('a purchase-only member applies to a purchase', function () {
    $line = app(PurchaseLinePricing::class)->quotePurchaseLine(plpQuote(plpGroup('PO', 'purchase')));

    expect($line->tax->total)->toBe('3.6000')
        ->and($line->lineTotal)->toBe('39.5996');
});

it('an inactive tax group is computed normally, not refused (T-029 K-12)', function () {
    $line = app(PurchaseLinePricing::class)->quotePurchaseLine(plpQuote(plpGroup('IA', 'both', false, ['is_active' => false])));

    expect($line->tax->total)->toBe('3.6000')
        ->and($line->lineTotal)->toBe('39.5996');
});

it('an unknown tax group is refused unknown_tax_group', function () {
    $reason = Build::reasonOf(fn () => app(PurchaseLinePricing::class)->quotePurchaseLine(plpQuote(Build::id('NOSUCH'))));

    expect($reason)->toBe('unknown_tax_group');
});

it('AC-43: a subtotal that overflows DECIMAL(18,4) is out_of_range', function () {
    $group = plpGroup('OV');
    $quote = new PurchaseLineQuote('99999999999999.9999', '2.0000', '0.0000', $group, '1.0000', Build::DATE);

    expect(Build::reasonOf(fn () => app(PurchaseLinePricing::class)->quotePurchaseLine($quote)))->toBe('out_of_range');
});

it('resolves both contracts to the one engine instance', function () {
    expect(app(PurchaseLinePricing::class))->toBe(app(PricingEngine::class));
});
