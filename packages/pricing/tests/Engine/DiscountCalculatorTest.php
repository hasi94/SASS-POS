<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Brick\Math\BigDecimal;
use Flexipos\Pricing\Engine\DiscountCalculator;
use Flexipos\Pricing\Engine\DiscountRule;
use Flexipos\Pricing\Engine\DiscountScope;
use Flexipos\Pricing\Engine\DiscountType;
use Flexipos\Pricing\Engine\ProductFacts;
use Flexipos\Pricing\Tests\Support\Build;

/*
|--------------------------------------------------------------------------
| DiscountCalculator (T-029 step 9, AC-20, AC-22.2, AC-23; D-18, D-19)
|--------------------------------------------------------------------------
|
| The refusal order is pinned by peeling one violation at a time off a rule
| that breaks every check, so a reordering of any two checks goes red.
*/

function discountFacts(bool $allow = true, ?string $category = null, ?string $brand = null): ProductFacts
{
    return new ProductFacts(Build::id('PRODA'), $category, $brand, $allow);
}

function discountLine(?DiscountRule $rule, string $subtotal = '179.0000', ?ProductFacts $facts = null, string $at = Build::AT): string
{
    return (string) DiscountCalculator::forLine($rule, $at, $facts ?? discountFacts(), BigDecimal::of($subtotal));
}

it('takes a percentage of the subtotal, rounded half-up, and caps it at max_discount', function (string $subtotal, string $value, ?string $cap, string $expected) {
    expect(discountLine(Build::discount(value: $value, maxDiscount: $cap), $subtotal))->toBe($expected);
})->with([
    '10 % of 179' => ['179.0000', '10.0000', null, '17.9000'],
    'capped at 15' => ['179.0000', '10.0000', '15.0000', '15.0000'],
    'a cap that is not reached' => ['179.0000', '10.0000', '50.0000', '17.9000'],
    'a cap equal to the amount' => ['179.0000', '10.0000', '17.9000', '17.9000'],
    '7.5 % of 33.3333 is 2.4999975' => ['33.3333', '7.5000', null, '2.5000'],
    'a zero subtotal' => ['0.0000', '10.0000', null, '0.0000'],
    '100 % takes the whole subtotal' => ['179.0000', '100.0000', null, '179.0000'],
]);

it('takes a fixed amount but never more than the subtotal', function (string $subtotal, string $value, string $expected) {
    expect(discountLine(Build::discount(type: DiscountType::Fixed, value: $value), $subtotal))->toBe($expected);
})->with([
    'a fixed 20 on 179' => ['179.0000', '20.0000', '20.0000'],
    'a fixed 200 on 179 is 179, never negative' => ['179.0000', '200.0000', '179.0000'],
    'exactly the subtotal' => ['179.0000', '179.0000', '179.0000'],
    'a fixed discount ignores max_discount' => ['179.0000', '100.0000', '100.0000'],
]);

it('does not apply max_discount to a fixed discount', function () {
    expect(discountLine(Build::discount(type: DiscountType::Fixed, value: '100.0000', maxDiscount: '10.0000')))->toBe('100.0000');
});

it('pins the line refusal order by peeling one violation at a time off a rule that breaks every check', function () {
    $other = [Build::id('PRODOTHER')];
    $state = [
        'scope' => DiscountScope::Bill,
        'active' => false,
        'starts' => '2025-07-01T00:00:00.000Z',
        'ends' => '2025-06-01T00:00:00.000Z',
        'limit' => 1,
        'used' => 1,
        'allow' => false,
        'products' => $other,
        'min' => '200.0000',
        'type' => DiscountType::BuyXGetY,
    ];

    $attempt = function () use (&$state): ?string {
        return Build::reasonOf(fn () => discountLine(
            Build::discount(type: $state['type'], scope: $state['scope'], active: $state['active'], startsAt: $state['starts'], endsAt: $state['ends'], limit: $state['limit'], used: $state['used'], minAmount: $state['min'], products: $state['products']),
            '179.0000',
            discountFacts($state['allow']),
        ));
    };

    $fixes = [
        'discount_scope_mismatch' => static function (array &$s): void {
            $s['scope'] = DiscountScope::Item;
        },
        'inactive' => static function (array &$s): void {
            $s['active'] = true;
        },
        'not_yet_started' => static function (array &$s): void {
            $s['starts'] = null;
        },
        'expired' => static function (array &$s): void {
            $s['ends'] = null;
        },
        'usage_limit_reached' => static function (array &$s): void {
            $s['limit'] = null;
        },
        'discount_not_allowed' => static function (array &$s): void {
            $s['allow'] = true;
        },
        'not_applicable' => static function (array &$s): void {
            $s['products'] = [];
        },
        'below_min_amount' => static function (array &$s): void {
            $s['min'] = null;
        },
        'unsupported_discount_type' => static function (array &$s): void {
            $s['type'] = DiscountType::Percentage;
        },
    ];

    foreach ($fixes as $reason => $fix) {
        expect($attempt())->toBe($reason);
        $fix($state);
    }

    expect($attempt())->toBeNull();
});

it('refuses an unknown discount before anything else', function () {
    expect(Build::reasonOf(fn () => discountLine(null)))->toBe('unknown_discount')
        ->and(Build::reasonOf(fn () => DiscountCalculator::forBill(null, Build::AT, ['10.0000'])))->toBe('unknown_discount');
});

it('judges every window check against the document instant, never the clock', function () {
    $window = Build::discount(startsAt: '2025-06-01T00:00:00.000Z', endsAt: '2025-06-30T23:59:59.999Z');

    expect(discountLine($window))->toBe('17.9000')
        // The same window, a document dated before it: the clock plays no part.
        ->and(Build::reasonOf(fn () => discountLine($window, at: '2025-05-31T23:59:59.999Z')))->toBe('not_yet_started')
        ->and(Build::reasonOf(fn () => discountLine($window, at: '2025-07-01T00:00:00.000Z')))->toBe('expired')
        ->and(discountLine($window, at: '2025-06-30T23:59:59.999Z'))->toBe('17.9000')
        ->and(discountLine($window, at: '2025-06-01T00:00:00.000Z'))->toBe('17.9000');
});

it('refuses at the usage limit and accepts one use short of it', function () {
    expect(Build::reasonOf(fn () => discountLine(Build::discount(limit: 5, used: 5))))->toBe('usage_limit_reached')
        ->and(Build::reasonOf(fn () => discountLine(Build::discount(limit: 5, used: 6))))->toBe('usage_limit_reached')
        ->and(discountLine(Build::discount(limit: 5, used: 4)))->toBe('17.9000')
        ->and(discountLine(Build::discount(limit: null, used: 999)))->toBe('17.9000');
});

it('matches applies_to by product, category or brand, and a null or empty applies_to matches everything', function (array $rule, ?string $category, ?string $brand, bool $applies) {
    $discount = Build::discount(...$rule);
    $facts = discountFacts(true, $category, $brand);

    expect(Build::reasonOf(fn () => discountLine($discount, '179.0000', $facts)))->toBe($applies ? null : 'not_applicable');
})->with([
    'empty applies to everything' => [[], null, null, true],
    'the product is named' => [['products' => [Build::id('PRODA')]], null, null, true],
    'only another product is named' => [['products' => [Build::id('PRODB')]], null, null, false],
    'the category is named' => [['categories' => [Build::id('CATA')]], Build::id('CATA'), null, true],
    'another category is named' => [['categories' => [Build::id('CATB')]], Build::id('CATA'), null, false],
    'the brand is named' => [['brands' => [Build::id('BRANDA')]], null, Build::id('BRANDA'), true],
    'OR across the three: only the brand matches' => [['products' => [Build::id('PRODB')], 'categories' => [Build::id('CATB')], 'brands' => [Build::id('BRANDA')]], Build::id('CATA'), Build::id('BRANDA'), true],
    'a product with no category never matches a category list' => [['categories' => [Build::id('CATA')]], null, null, false],
]);

it('refuses below min_amount on the subtotal and accepts a subtotal equal to it', function () {
    expect(Build::reasonOf(fn () => discountLine(Build::discount(minAmount: '200.0000'))))->toBe('below_min_amount')
        ->and(Build::reasonOf(fn () => discountLine(Build::discount(minAmount: '179.0001'))))->toBe('below_min_amount')
        ->and(discountLine(Build::discount(minAmount: '179.0000')))->toBe('17.9000');
});

it('refuses a percentage above 100 and a malformed rule by name', function () {
    expect(Build::reasonOf(fn () => Build::discount(value: '100.0001')))->toBe('invalid_amount')
        ->and(Build::discount(type: DiscountType::Fixed, value: '100000.0000')->value)->toBe('100000.0000')
        ->and(Build::reasonOf(fn () => Build::discount(value: '-1.0000')))->toBe('invalid_amount')
        ->and(Build::reasonOf(fn () => Build::discount(maxDiscount: '1.00005')))->toBe('invalid_amount')
        ->and(fn () => Build::discount(startsAt: '2025-06-15'))->toThrow(\InvalidArgumentException::class, '[startsAt]')
        ->and(fn () => Build::discount(used: -1))->toThrow(\InvalidArgumentException::class, '[usedCount]')
        ->and(fn () => discountLine(Build::discount(), at: 'not an instant'))->toThrow(\InvalidArgumentException::class, '[at]');
});

/*
|--------------------------------------------------------------------------
| Bill discounts (AC-23, D-19)
|--------------------------------------------------------------------------
*/

it('bases a bill discount on the summed discounted subtotals', function (array $subtotals, string $base, string $expected, array $rule) {
    $bill = DiscountCalculator::forBill(Build::discount(...[...$rule, 'scope' => DiscountScope::Bill]), Build::AT, $subtotals);

    expect($bill->base)->toBe($base)
        ->and($bill->amount)->toBe($expected)
        ->and($bill->discountId)->toBe(Build::id('DISC1'));
})->with([
    '5 % of 175' => [['100.0000', '50.0000', '25.0000'], '175.0000', '8.7500', ['value' => '5.0000']],
    'capped at 5' => [['100.0000', '50.0000', '25.0000'], '175.0000', '5.0000', ['value' => '5.0000', 'maxDiscount' => '5.0000']],
    'a fixed 500 on 175 is 175' => [['100.0000', '50.0000', '25.0000'], '175.0000', '175.0000', ['type' => DiscountType::Fixed, 'value' => '500.0000']],
    'no lines is a zero base' => [[], '0.0000', '0.0000', ['value' => '5.0000']],
    'a single line' => [['33.3333'], '33.3333', '1.6667', ['value' => '5.0000']],
]);

it('refuses an item discount on a bill, and the bill-side checks in order', function () {
    $subtotals = ['100.0000'];

    expect(Build::reasonOf(fn () => DiscountCalculator::forBill(Build::discount(scope: DiscountScope::Item), Build::AT, $subtotals)))->toBe('discount_scope_mismatch');

    $bill = static fn (array $args): ?string => Build::reasonOf(fn () => DiscountCalculator::forBill(Build::discount(...[...$args, 'scope' => DiscountScope::Bill]), Build::AT, $subtotals));

    // product-side checks (allow, applies_to) do not exist on a bill: nothing here refuses for them.
    expect($bill(['active' => false]))->toBe('inactive')
        ->and($bill(['startsAt' => '2025-07-01T00:00:00.000Z']))->toBe('not_yet_started')
        ->and($bill(['endsAt' => '2025-06-01T00:00:00.000Z']))->toBe('expired')
        ->and($bill(['limit' => 1, 'used' => 1]))->toBe('usage_limit_reached')
        ->and($bill(['minAmount' => '100.0001']))->toBe('below_min_amount')
        ->and($bill(['minAmount' => '100.0000']))->toBeNull()
        ->and($bill(['type' => DiscountType::BuyXGetY]))->toBe('unsupported_discount_type')
        ->and($bill(['type' => DiscountType::BuyXGetY, 'minAmount' => '200.0000']))->toBe('below_min_amount');
});

it('refuses a malformed discounted subtotal by name', function () {
    expect(Build::reasonOf(fn () => DiscountCalculator::forBill(Build::discount(scope: DiscountScope::Bill), Build::AT, ['-1.0000'])))->toBe('invalid_amount')
        ->and(Build::reasonOf(fn () => DiscountCalculator::forBill(Build::discount(scope: DiscountScope::Bill), Build::AT, ['99999999999999.9999', '0.0001'])))->toBe('out_of_range');
});

it('accepts a percentage discount of exactly 100 and refuses one a hair above (the numeric cap)', function () {
    expect(Build::discount(value: '100.0000')->value)->toBe('100.0000')
        ->and(Build::reasonOf(fn () => Build::discount(value: '100.0001')))->toBe('invalid_amount');
});
