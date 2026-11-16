<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Contracts;

use Flexipos\Contracts\Pricing\PriceBasis;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\TaxContext;
use Flexipos\Contracts\Pricing\TaxGroupState;

/*
|--------------------------------------------------------------------------
| The published vocabularies (T-029 step 5, plan Ruling 2)
|--------------------------------------------------------------------------
|
| Each enum is asserted as an exact, ordered list: a case added or renamed
| without this file knowing is a change to a published contract and must be a
| decision, not an accident.
*/

it('has exactly the seventeen refusal reasons (plan Ruling 2)', function () {
    expect(array_map(fn (PricingRefusal $case): string => $case->value, PricingRefusal::cases()))->toBe([
        'unknown_product', 'unknown_tax_group', 'no_effective_rate', 'inclusive_amount_too_small', 'no_price_resolved',
        'unknown_discount', 'discount_scope_mismatch', 'inactive', 'not_yet_started', 'expired', 'usage_limit_reached',
        'discount_not_allowed', 'not_applicable', 'below_min_amount', 'unsupported_discount_type', 'invalid_amount',
        'out_of_range',
    ]);
});

it('has exactly the two tax contexts and the three tax group states', function () {
    expect(array_map(fn (TaxContext $case): string => $case->value, TaxContext::cases()))->toBe(['sale', 'purchase'])
        ->and(array_map(fn (TaxGroupState $case): string => $case->value, TaxGroupState::cases()))->toBe(['unknown', 'active', 'inactive']);
});

it('has exactly the three price bases', function () {
    expect(array_map(fn (PriceBasis $case): string => $case->value, PriceBasis::cases()))->toBe(['unit_matched', 'derived', 'product']);
});

it('has a named constructor for every refusal reason, each carrying exactly its own reason', function () {
    $named = [
        'unknownProduct' => PricingRefusal::UnknownProduct,
        'unknownTaxGroup' => PricingRefusal::UnknownTaxGroup,
        'noEffectiveRate' => PricingRefusal::NoEffectiveRate,
        'inclusiveAmountTooSmall' => PricingRefusal::InclusiveAmountTooSmall,
        'noPriceResolved' => PricingRefusal::NoPriceResolved,
        'unknownDiscount' => PricingRefusal::UnknownDiscount,
        'discountScopeMismatch' => PricingRefusal::DiscountScopeMismatch,
        'inactive' => PricingRefusal::Inactive,
        'notYetStarted' => PricingRefusal::NotYetStarted,
        'expired' => PricingRefusal::Expired,
        'usageLimitReached' => PricingRefusal::UsageLimitReached,
        'discountNotAllowed' => PricingRefusal::DiscountNotAllowed,
        'notApplicable' => PricingRefusal::NotApplicable,
        'belowMinAmount' => PricingRefusal::BelowMinAmount,
        'unsupportedDiscountType' => PricingRefusal::UnsupportedDiscountType,
        'invalidAmount' => PricingRefusal::InvalidAmount,
        'outOfRange' => PricingRefusal::OutOfRange,
    ];

    // Every reason is reachable through a named constructor, and none is built any other way.
    expect(array_values($named))->toEqualCanonicalizing(PricingRefusal::cases());

    foreach ($named as $constructor => $reason) {
        $refused = PricingRefused::$constructor('message for '.$constructor);

        expect($refused->reason)->toBe($reason)
            ->and($refused->getMessage())->toBe('message for '.$constructor);
    }

    expect((new \ReflectionClass(PricingRefused::class))->getConstructor()?->isPrivate())->toBeTrue();
});
