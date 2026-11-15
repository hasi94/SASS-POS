<?php

namespace Flexipos\Sales\Tests\Support;

use Brick\Math\BigDecimal;
use Flexipos\Sales\SalesServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

/**
 * T-030 step 1: the package is wired into the host and `brick/math` is
 * reachable from it, before any Sales code exists (plan, chunk A).
 */
it('loads the sales provider', function () {
    expect(app()->getLoadedProviders())->toHaveKey(SalesServiceProvider::class);
});

it('can autoload brick/math BigDecimal, the one Sales arithmetic library (Ruling 3)', function () {
    $value = BigDecimal::of('7.5000')->multipliedBy('3.0000');

    expect($value->__toString())->toBe('22.50000000');
});
