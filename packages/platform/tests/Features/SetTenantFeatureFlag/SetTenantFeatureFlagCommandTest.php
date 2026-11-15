<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\SetTenantFeatureFlag;

use Flexipos\Contracts\Platform\FeatureFlags;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 AC-33, BUG-2 - platform:set-tenant-feature-flag
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    PlatformSeed::bindMasterSource();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

it('sets an override with --enable, and the resolver sees it afterwards', function () {
    PlatformSeed::featureFlag('demo.flag', false);
    $tenantId = PlatformSeed::tenant();

    $exitCode = Artisan::call('platform:set-tenant-feature-flag', [
        'tenant' => $tenantId,
        'flag' => 'demo.flag',
        '--enable' => true,
        '--reason' => 'pilot',
        '--actor' => 'op-1',
    ]);

    expect($exitCode)->toBe(0)
        ->and(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeTrue();
});

it('clears an override with --clear, falling back to the default', function () {
    PlatformSeed::featureFlag('demo.flag', true);
    $tenantId = PlatformSeed::tenant();

    Artisan::call('platform:set-tenant-feature-flag', [
        'tenant' => $tenantId,
        'flag' => 'demo.flag',
        '--disable' => true,
    ]);

    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeFalse();

    $exitCode = Artisan::call('platform:set-tenant-feature-flag', [
        'tenant' => $tenantId,
        'flag' => 'demo.flag',
        '--clear' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeTrue();
});

it('refuses, exiting non-zero, when none or more than one of --enable/--disable/--clear is given', function () {
    $tenantId = PlatformSeed::tenant();

    $none = Artisan::call('platform:set-tenant-feature-flag', [
        'tenant' => $tenantId,
        'flag' => 'demo.flag',
    ]);

    $both = Artisan::call('platform:set-tenant-feature-flag', [
        'tenant' => $tenantId,
        'flag' => 'demo.flag',
        '--enable' => true,
        '--disable' => true,
    ]);

    expect($none)->not->toBe(0)
        ->and($both)->not->toBe(0);
});
