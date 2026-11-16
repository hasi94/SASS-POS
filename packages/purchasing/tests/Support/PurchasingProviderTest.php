<?php

namespace Flexipos\Purchasing\Tests\Support;

use Brick\Math\BigDecimal;
use Flexipos\Purchasing\PurchasingServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Step 1: the package is wired into the host and `brick/math` is reachable
 * from it, before any purchasing code exists (plan chunk A).
 */
it('loads the purchasing provider', function () {
    expect(app()->getLoadedProviders())->toHaveKey(PurchasingServiceProvider::class);
});

it('can autoload brick/math BigDecimal, the one purchasing arithmetic library', function () {
    $value = BigDecimal::of('1.0000')->dividedBy('0.0001', 4);

    expect($value->__toString())->toBe('10000.0000');
});
