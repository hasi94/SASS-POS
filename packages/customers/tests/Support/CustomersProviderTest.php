<?php

namespace Flexipos\Customers\Tests\Support;

use Brick\Math\BigDecimal;
use Flexipos\Customers\CustomersServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Step 1: the package is wired into the host and `brick/math` is reachable
 * from it, before any customers code exists (plan section 6, chunk A).
 */
it('loads the customers provider', function () {
    expect(app()->getLoadedProviders())->toHaveKey(CustomersServiceProvider::class);
});

it('can autoload brick/math BigDecimal, the one ledger/loyalty arithmetic library (Ruling 3)', function () {
    $value = BigDecimal::of('1.0000')->dividedBy('0.0001', 4);

    expect($value->__toString())->toBe('10000.0000');
});
