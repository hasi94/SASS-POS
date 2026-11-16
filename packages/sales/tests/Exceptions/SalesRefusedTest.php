<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Exceptions;

use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Customers\CreditLedgerRefused;
use Flexipos\Contracts\Customers\CreditRefusal;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;

/*
|--------------------------------------------------------------------------
| T-030 step 14 - how a foreign refusal is carried (Ruling 8 amendment, R-40)
|--------------------------------------------------------------------------
|
| A pricing, unit or credit refusal keeps its own enum as the cause, and the code
| a caller sees is the pricing refusal's own value (AC-5), `unresolvable_price`
| for the one the real engine cannot raise (R-3), and the Sales reason otherwise.
*/

it('carries a pricing refusal under its own value, with the cause attached', function (string $constructor, PricingRefusal $reason) {
    $refused = SalesRefused::fromPricing(PricingRefused::{$constructor}('m'));

    expect($refused->reason)->toBe(SalesRefusal::PricingRefused)
        ->and($refused->cause)->toBe($reason)
        ->and($refused->code())->toBe($reason->value);
})->with([
    'unknown_product' => ['unknownProduct', PricingRefusal::UnknownProduct],
    'not_applicable' => ['notApplicable', PricingRefusal::NotApplicable],
    'usage_limit_reached' => ['usageLimitReached', PricingRefusal::UsageLimitReached],
    'out_of_range' => ['outOfRange', PricingRefusal::OutOfRange],
    'invalid_amount' => ['invalidAmount', PricingRefusal::InvalidAmount],
]);

it('names no_price_resolved unresolvable_price', function () {
    $refused = SalesRefused::fromPricing(PricingRefused::noPriceResolved('none'));

    expect($refused->reason)->toBe(SalesRefusal::UnresolvablePrice)
        ->and($refused->cause)->toBe(PricingRefusal::NoPriceResolved)
        ->and($refused->code())->toBe('unresolvable_price');
});

it('names a unit refusal unit_unresolvable, and the inherited mismatch by its own name', function () {
    $cross = SalesRefused::fromUnit(UnitResolutionRefused::crossDimension('x'));
    $inherited = SalesRefused::fromUnit(UnitResolutionRefused::inheritedUnitMismatch('x'));

    expect($cross->reason)->toBe(SalesRefusal::UnitUnresolvable)
        ->and($cross->cause)->toBe(UnitRefusal::CrossDimension)
        ->and($inherited->reason)->toBe(SalesRefusal::InheritedUnitMismatch)
        ->and($inherited->cause)->toBe(UnitRefusal::InheritedUnitMismatch)
        ->and(SalesRefused::fromUnit(UnitResolutionRefused::unknownUnit('x'), 'a better message')->getMessage())->toBe('a better message');
});

it('names a credit refusal credit_refused with the ledger\'s cause', function () {
    $refused = SalesRefused::fromCredit(CreditLedgerRefused::overLimit('over'));

    expect($refused->reason)->toBe(SalesRefusal::CreditRefused)
        ->and($refused->cause)->toBe(CreditRefusal::OverLimit)
        ->and($refused->code())->toBe('credit_refused');
});

it('reads a Sales reason as its own code when nothing foreign is carried', function () {
    expect(SalesRefused::validation('x')->code())->toBe('validation')
        ->and(SalesRefused::because(SalesRefusal::ReplayConflict, 'x', CreditRefusal::ReplayConflict)->code())->toBe('replay_conflict');
});
