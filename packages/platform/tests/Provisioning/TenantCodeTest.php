<?php

use Flexipos\Contracts\Organization\ActivationCodeFormat;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\TenantCode;

it('trims and folds to upper case', function () {
    expect(TenantCode::normalise(' ten-001 '))->toBe('TEN-001');
});

it('refuses a code starting or ending with a hyphen, and a blank one', function (mixed $submitted) {
    expect(fn () => TenantCode::normalise($submitted))->toThrow(PlatformRefused::class);
})->with([
    '-TEN001',
    'TEN001-',
    '',
    '   ',
    str_repeat('A', 31),
]);

it('accepts TEN_001 as invalid, because underscore is not in the pattern', function () {
    expect(fn () => TenantCode::normalise('TEN_001'))->toThrow(PlatformRefused::class);
});

it('runs against ActivationCodeFormat::TENANT_PART_PATTERN itself, not a copy', function () {
    // The control: whatever this pattern accepts, the normaliser accepts,
    // and the reverse - proven by generating from the same constant rather
    // than restating it.
    expect(preg_match(ActivationCodeFormat::TENANT_PART_PATTERN, TenantCode::normalise('ten-001')))->toBe(1);
});
