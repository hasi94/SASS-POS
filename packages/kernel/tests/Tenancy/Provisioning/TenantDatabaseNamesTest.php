<?php

use Flexipos\Kernel\Tenancy\Provisioning\Exceptions\TenantNameRejected;
use Flexipos\Kernel\Tenancy\Provisioning\TenantDatabaseNames;

it('names sequence 1 mechanically', function () {
    expect(TenantDatabaseNames::databaseName(1))->toBe('flx_t_000001')
        ->and(TenantDatabaseNames::appUsername(1))->toBe('flx_t000001_app')
        ->and(TenantDatabaseNames::migrateUsername(1))->toBe('flx_t000001_mig');
});

it('keeps six digits at 999999 and widens at 1000000', function () {
    expect(TenantDatabaseNames::databaseName(999999))->toBe('flx_t_999999')
        ->and(TenantDatabaseNames::databaseName(1000000))->toBe('flx_t_1000000')
        ->and(TenantDatabaseNames::appUsername(1000000))->toBe('flx_t1000000_app');
});

it('fits every produced name within 32 characters', function () {
    foreach ([1, 999999, 1000000, 9999999] as $sequence) {
        expect(strlen(TenantDatabaseNames::databaseName($sequence)))->toBeLessThanOrEqual(32)
            ->and(strlen(TenantDatabaseNames::appUsername($sequence)))->toBeLessThanOrEqual(32)
            ->and(strlen(TenantDatabaseNames::migrateUsername($sequence)))->toBeLessThanOrEqual(32);
    }
});

it('accepts a mechanically produced name through its own guard', function () {
    TenantDatabaseNames::assertDatabaseName(TenantDatabaseNames::databaseName(42));
    TenantDatabaseNames::assertUsername(TenantDatabaseNames::appUsername(42));
    TenantDatabaseNames::assertUsername(TenantDatabaseNames::migrateUsername(42));
})->throwsNoExceptions();

it('refuses a quote, a backtick, upper case and the wrong prefix', function (string $name) {
    expect(fn () => TenantDatabaseNames::assertDatabaseName($name))->toThrow(TenantNameRejected::class);
})->with([
    "flx_t_00000'1",
    'flx_t_00000`1',
    'FLX_T_000001',
    'wrong_prefix_000001',
    '',
]);

it('refuses a username of the wrong shape', function (string $name) {
    expect(fn () => TenantDatabaseNames::assertUsername($name))->toThrow(TenantNameRejected::class);
})->with([
    'flx_t000001_app`',
    'FLX_T000001_APP',
    'flx_t000001_other',
    'notflx_t000001_app',
]);
