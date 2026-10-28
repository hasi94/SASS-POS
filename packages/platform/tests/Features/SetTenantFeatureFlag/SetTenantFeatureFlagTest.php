<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\SetTenantFeatureFlag;

use Flexipos\Contracts\Platform\FeatureFlags;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Features\SetTenantFeatureFlag\SetTenantFeatureFlag;
use Flexipos\Platform\Features\SetTenantFeatureFlag\SetTenantFeatureFlagHandler;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 8 - SetTenantFeatureFlag (override set/clear)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    PlatformSeed::bindMasterSource();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

it('upserts an override, and the resolver sees it afterwards', function () {
    PlatformSeed::featureFlag('demo.flag', false);
    $tenantId = PlatformSeed::tenant();

    app(SetTenantFeatureFlagHandler::class)->handle(new SetTenantFeatureFlag($tenantId, 'demo.flag', true));

    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeTrue();

    app(SetTenantFeatureFlagHandler::class)->handle(new SetTenantFeatureFlag($tenantId, 'demo.flag', false, 'support ticket'));

    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeFalse();
});

it('clears an override when enabled is null, falling back to the default', function () {
    PlatformSeed::featureFlag('demo.flag', true);
    $tenantId = PlatformSeed::tenant();

    app(SetTenantFeatureFlagHandler::class)->handle(new SetTenantFeatureFlag($tenantId, 'demo.flag', false));
    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeFalse();

    app(SetTenantFeatureFlagHandler::class)->handle(new SetTenantFeatureFlag($tenantId, 'demo.flag', null));
    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeTrue();
});

it('refuses an unknown key', function () {
    $tenantId = PlatformSeed::tenant();

    expect(fn () => app(SetTenantFeatureFlagHandler::class)->handle(new SetTenantFeatureFlag($tenantId, 'no.such.flag', true)))
        ->toThrow(PlatformRefused::class);
});
