<?php

use Flexipos\Workflow\Conditions\DecimalComparator;
use Tests\TestCase;

uses(TestCase::class);

it('compares decimal strings and ints exactly, no bcmath, no float', function () {
    expect(DecimalComparator::compare('1000', '1000.0001'))->toBeLessThan(0)
        ->and(DecimalComparator::compare('-1', '0'))->toBeLessThan(0)
        ->and(DecimalComparator::compare('007', 7))->toBe(0)
        ->and(DecimalComparator::equal('007', 7))->toBeTrue()
        ->and(DecimalComparator::compare('99999999999999.9999', '99999999999999.9999'))->toBe(0)
        ->and(DecimalComparator::compare('0.5', '0.49'))->toBeGreaterThan(0);
});

it('is exact against the string a float would round', function () {
    // 1000.0001 as a float loses precision; the decimal-string path must not.
    expect(DecimalComparator::equal('1000.0001', '1000.0001'))->toBeTrue()
        ->and(DecimalComparator::compare('1000.0001', '1000.0002'))->toBeLessThan(0);
});
