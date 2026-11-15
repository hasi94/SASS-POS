<?php

namespace Flexipos\Pricing\Tests\Support;

use Brick\Math\BigDecimal;
use Flexipos\Pricing\PricingServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Step 1: the package is wired into the host and `brick/math` is reachable
 * from it, before any pricing code exists (plan section 6, chunk A).
 */
it('loads the pricing provider', function () {
    expect(app()->getLoadedProviders())->toHaveKey(PricingServiceProvider::class);
});

it('can autoload brick/math BigDecimal, the one pricing arithmetic library (Ruling 3)', function () {
    $value = BigDecimal::of('1.0000')->dividedBy('0.0001', 4);

    expect($value->__toString())->toBe('10000.0000');
});
