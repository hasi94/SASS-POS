<?php

declare(strict_types=1);

use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Tests\TestCase;

uses(TestCase::class);

it('has exactly the four ladder-step sources (plan Ruling 2)', function () {
    expect(array_map(fn (FactorSource $case): string => $case->value, FactorSource::cases()))->toBe([
        'product_conversion', 'global_conversion', 'unit_chain', 'stock_unit',
    ]);
});

it('has exactly the ten refusal reasons (plan Ruling 2)', function () {
    expect(array_map(fn (UnitRefusal $case): string => $case->value, UnitRefusal::cases()))->toBe([
        'unknown_product', 'unknown_unit', 'cross_dimension', 'no_conversion_path', 'factor_below_one',
        'inexact_factor', 'factor_out_of_range', 'invalid_quantity', 'quantity_out_of_range', 'fractional_base_quantity',
    ]);
});
