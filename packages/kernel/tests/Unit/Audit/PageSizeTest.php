<?php

use Flexipos\Kernel\Audit\PageSize;

/*
 * T-017 step 10 - the one clamp both audit readers use (Ruling 4).
 *
 * No TestCase binding: a pure function needs no application.
 */

it('gives the default for null or less than 1', function () {
    expect(PageSize::clamp(null, 50, 500))->toBe(50)
        ->and(PageSize::clamp(0, 50, 500))->toBe(50)
        ->and(PageSize::clamp(-1, 50, 500))->toBe(50);
});

it('clamps a value above the maximum to the maximum, never refusing it', function () {
    expect(PageSize::clamp(501, 50, 500))->toBe(500)
        ->and(PageSize::clamp(1_000_000, 50, 500))->toBe(500);
});

it('passes a value inside the bounds through unchanged', function () {
    expect(PageSize::clamp(10, 50, 500))->toBe(10)
        ->and(PageSize::clamp(500, 50, 500))->toBe(500);
});

it('moves with its arguments - no literal is baked in', function () {
    expect(PageSize::clamp(null, 7, 9))->toBe(7)
        ->and(PageSize::clamp(100, 7, 9))->toBe(9);
});
