<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Documents;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Purchasing\Documents\LineQuoter;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Tests\Support\PurchasingQueryLog;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 8 - LineQuoter (AC-31, AC-42, AC-43, AC-49, AC-94, D-14, D-53a)
|--------------------------------------------------------------------------
|
| The real UnitResolver and the real PurchaseLinePricing on the fixture tenant:
| the quote is the resolver's quad plus the engine's amounts, and nothing in
| between is computed by purchasing.
*/

uses(TestCase::class);

/** Measured on the SQLite stand-in: a change in any of the three is a change in the per-line cost (FP-426, AC-94). */
const PU_QUOTE_QUERIES = ['fresh' => 9, 'inherited' => 6, 'requote' => 4];

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

function puQuoter(): LineQuoter
{
    return app(LineQuoter::class);
}

/**
 * A product counted in each, with a dozen of twelve each.
 *
 * @return array{product: string, each: string, dozen: string}
 */
function puQuoterWorld(): array
{
    $units = PurchasingSeed::unitPair('EA', 'DZ', '12');

    return [
        'product' => PurchasingSeed::product('LQ-1', $units['base']),
        'each' => $units['base'],
        'dozen' => $units['large'],
    ];
}

function puQuoterRefusal(callable $call): PurchasingRefused
{
    try {
        $call();
    } catch (PurchasingRefused $refused) {
        return $refused;
    }

    throw new \LogicException('The call was expected to refuse and did not.');
}

it('AC-43: quotes the exclusive worked example through the real resolver and engine', function () {
    $w = puQuoterWorld();
    $group = PurchasingSeed::taxGroup('LQEX');

    $line = puQuoter()->quote($w['product'], $w['each'], '12.0000', '3.3333', '10.0000', $group, PurchasingSeed::DATE);

    expect($line->columns())->toBe([
        'unit_id' => $w['each'],
        'quantity' => '12.0000',
        'unit_factor' => '1.000000',
        'base_quantity' => '12.0000',
        'unit_cost' => '3.3333',
        'subtotal' => '39.9996',
        'discount_percent' => '10.0000',
        'discount_amount' => '4.0000',
        'tax_group_id' => $group,
        'tax_amount' => '3.6000',
        'line_total' => '39.5996',
    ]);
});

it('AC-43: quotes the inclusive twin, whose line total is the discounted net', function () {
    $w = puQuoterWorld();
    $group = PurchasingSeed::taxGroup('LQIN', '10.0000', true);

    $line = puQuoter()->quote($w['product'], $w['each'], '12.0000', '3.3333', '10.0000', $group, PurchasingSeed::DATE);

    expect($line->columns()['tax_amount'])->toBe('3.2727')
        ->and($line->columns()['line_total'])->toBe('35.9996');
});

it('AC-42: freezes a larger unit as its quantity, factor and base quantity, and prices the line unit s quantity', function () {
    $w = puQuoterWorld();
    $group = PurchasingSeed::taxGroup('LQDZ');

    $line = puQuoter()->quote($w['product'], $w['dozen'], '2.0000', '10.0000', '0.0000', $group, PurchasingSeed::DATE);

    expect($line->unit->unitId)->toBe($w['dozen'])
        ->and($line->unit->quantity)->toBe('2.0000')
        ->and($line->unit->unitFactor)->toBe('12.000000')
        ->and($line->unit->baseQuantity)->toBe('24.0000')
        ->and($line->columns()['subtotal'])->toBe('20.0000')
        ->and($line->columns()['tax_amount'])->toBe('2.0000')
        ->and($line->columns()['line_total'])->toBe('22.0000');
});

it('prices at the date the caller gives, so a rate that starts later applies only from then (the order date, not today)', function () {
    $w = puQuoterWorld();
    $tax = PricingSeed::tax(PricingSeed::authority('LQD'), 'LQDVAT');
    PricingSeed::rate($tax, '10.0000', '2020-01-01', '2026-12-31');
    PricingSeed::rate($tax, '20.0000', '2027-01-01');
    $group = PricingSeed::group('LQDG');
    PricingSeed::member($group, $tax, 1);

    $before = puQuoter()->quote($w['product'], $w['each'], '10.0000', '10.0000', '0.0000', $group, '2026-11-16');
    $after = puQuoter()->quote($w['product'], $w['each'], '10.0000', '10.0000', '0.0000', $group, '2027-02-01');

    expect($before->columns()['tax_amount'])->toBe('10.0000')
        ->and($after->columns()['tax_amount'])->toBe('20.0000');
});

it('AC-31: keeps the request line s stored factor when the ladder has moved, with snapshotInherited', function () {
    $w = puQuoterWorld();
    $group = PurchasingSeed::taxGroup('LQIH');

    // The request line froze a dozen as ten each; the ladder says twelve today.
    $line = puQuoter()->quoteInherited($w['product'], $w['dozen'], '10.000000', $w['dozen'], '3.0000', '4.0000', '0.0000', $group, PurchasingSeed::DATE);

    expect($line->unit->unitFactor)->toBe('10.000000')
        ->and($line->unit->baseQuantity)->toBe('30.0000')
        ->and($line->columns()['subtotal'])->toBe('12.0000');
});

it('AC-49: re-quotes a stored quad under a new cost without touching the resolver', function () {
    $group = PurchasingSeed::taxGroup('LQRQ');

    $priced = null;
    $log = PurchasingQueryLog::during(function () use (&$priced, $group): void {
        $priced = puQuoter()->price('2.0000', '24.0000', '20.0000', '0.0000', $group, PurchasingSeed::DATE);
    });

    expect($priced?->subtotal)->toBe('40.0000')
        ->and($priced?->lineTotal)->toBe('44.0000');

    foreach ($log as $sql) {
        expect($sql)->not->toMatch('/\bunits\b|unit_conversions/');
    }
});

it('refuses a unit the resolver cannot resolve as unit_unresolvable, carrying the resolver s reason', function (string $case, UnitRefusal $cause) {
    $w = puQuoterWorld();
    $group = PurchasingSeed::taxGroup('LQUR');

    [$product, $unit] = match ($case) {
        'unknown unit' => [$w['product'], (string) Str::ulid()],
        'unknown product' => [(string) Str::ulid(), $w['each']],
        'another dimension' => [$w['product'], PricingSeed::unit('LQKG', 'mass')],
        default => throw new \LogicException("Unhandled case [{$case}]."),
    };

    $refused = puQuoterRefusal(fn () => puQuoter()->quote($product, $unit, '1.0000', '1.0000', '0.0000', $group, PurchasingSeed::DATE));

    expect($refused->code())->toBe('unit_unresolvable')
        ->and($refused->cause)->toBe($cause);
})->with([
    'unknown unit' => ['unknown unit', UnitRefusal::UnknownUnit],
    'unknown product' => ['unknown product', UnitRefusal::UnknownProduct],
    'another dimension' => ['another dimension', UnitRefusal::CrossDimension],
]);

it('refuses an inherited unit that is neither the stored one nor the stock unit as inherited_unit_mismatch', function () {
    $w = puQuoterWorld();
    $group = PurchasingSeed::taxGroup('LQIM');
    $other = PricingSeed::unit('LQBX', 'count', ['base_unit_id' => $w['each'], 'factor' => '6']);

    $refused = puQuoterRefusal(fn () => puQuoter()->quoteInherited($w['product'], $w['dozen'], '12.000000', $other, '1.0000', '1.0000', '0.0000', $group, PurchasingSeed::DATE));

    expect($refused->code())->toBe('inherited_unit_mismatch')
        ->and($refused->cause)->toBe(UnitRefusal::InheritedUnitMismatch);
});

it('refuses an unknown tax group as pricing_refused with the engine s reason as cause, and a discount over 100 the same way', function () {
    $w = puQuoterWorld();
    $group = PurchasingSeed::taxGroup('LQPR');

    $unknown = puQuoterRefusal(fn () => puQuoter()->quote($w['product'], $w['each'], '1.0000', '1.0000', '0.0000', (string) Str::ulid(), PurchasingSeed::DATE));
    $discount = puQuoterRefusal(fn () => puQuoter()->quote($w['product'], $w['each'], '1.0000', '1.0000', '100.0001', $group, PurchasingSeed::DATE));

    expect($unknown->code())->toBe('pricing_refused')
        ->and($unknown->cause)->toBe(PricingRefusal::UnknownTaxGroup)
        ->and($discount->code())->toBe('pricing_refused')
        ->and($discount->cause)->toBeInstanceOf(PricingRefusal::class);
});

it('AC-47: refuses a line that cannot fit DECIMAL(18,4) as out_of_range, carrying the engine s reason', function () {
    $w = puQuoterWorld();
    $group = PurchasingSeed::taxGroup('LQOR');

    $refused = puQuoterRefusal(fn () => puQuoter()->quote($w['product'], $w['each'], '10.0000', '99999999999999.9999', '0.0000', $group, PurchasingSeed::DATE));

    expect($refused->code())->toBe('out_of_range')
        ->and($refused->cause)->toBe(PricingRefusal::OutOfRange);
});

it('AC-94: records the measured query counts for one line, so the cost per line is known and does not grow unnoticed', function () {
    $w = puQuoterWorld();
    $group = PurchasingSeed::taxGroup('LQQC');

    $fresh = count(PurchasingQueryLog::during(fn () => puQuoter()->quote($w['product'], $w['dozen'], '2.0000', '10.0000', '0.0000', $group, PurchasingSeed::DATE)));
    $inherited = count(PurchasingQueryLog::during(fn () => puQuoter()->quoteInherited($w['product'], $w['dozen'], '12.000000', $w['dozen'], '2.0000', '10.0000', '0.0000', $group, PurchasingSeed::DATE)));
    $requote = count(PurchasingQueryLog::during(fn () => puQuoter()->price('2.0000', '24.0000', '10.0000', '0.0000', $group, PurchasingSeed::DATE)));

    fwrite(STDOUT, "\nAC-94 query counts, one line: snapshot+quote={$fresh}, snapshotInherited+quote={$inherited}, quote only={$requote}\n");

    expect($fresh)->toBe(PU_QUOTE_QUERIES['fresh'])
        ->and($inherited)->toBe(PU_QUOTE_QUERIES['inherited'])
        ->and($requote)->toBe(PU_QUOTE_QUERIES['requote']);
});
