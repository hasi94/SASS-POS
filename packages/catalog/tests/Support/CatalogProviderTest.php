<?php

namespace Flexipos\Catalog\Tests\Support;

use Brick\Math\BigDecimal;
use Flexipos\Catalog\CatalogServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Step 1: the package is wired into the host and `brick/math` is reachable
 * from it, before any catalog code exists (plan section 6, chunk A).
 */
it('loads the catalog provider', function () {
    expect(app()->getLoadedProviders())->toHaveKey(CatalogServiceProvider::class);
});

it('can autoload brick/math BigDecimal, the one ladder/snapshot arithmetic library (Ruling 5)', function () {
    $value = BigDecimal::of('1.000000')->dividedBy('0.001', 6);

    expect($value->__toString())->toBe('1000.000000');
});
