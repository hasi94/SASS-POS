<?php

declare(strict_types=1);

use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Tests\TestCase;

uses(TestCase::class);

it('has exactly the four ladder-step sources plus T-030s inherited one (plan Ruling 2; T-030 D-32)', function () {
    expect(array_map(fn (FactorSource $case): string => $case->value, FactorSource::cases()))->toBe([
        'product_conversion', 'global_conversion', 'unit_chain', 'stock_unit', 'inherited',
    ]);
});

it('has exactly the ten refusal reasons plus T-030s inherited_unit_mismatch (plan Ruling 2; T-030 D-32)', function () {
    expect(array_map(fn (UnitRefusal $case): string => $case->value, UnitRefusal::cases()))->toBe([
        'unknown_product', 'unknown_unit', 'cross_dimension', 'no_conversion_path', 'factor_below_one',
        'inexact_factor', 'factor_out_of_range', 'invalid_quantity', 'quantity_out_of_range', 'fractional_base_quantity',
        'inherited_unit_mismatch',
    ]);
});
