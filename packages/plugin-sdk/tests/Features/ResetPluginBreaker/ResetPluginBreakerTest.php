<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\ResetPluginBreaker;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\PluginSdk\Breaker\CircuitBreaker;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Features\ResetPluginBreaker\ResetPluginBreaker;
use Flexipos\PluginSdk\Features\ResetPluginBreaker\ResetPluginBreakerHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Tests\TestCase;

require_once __DIR__.'/../../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 11 - ResetPluginBreakerHandler (AC-33, AC-41)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PluginSeed::migrate();
    AccessTenantFixture::boot();
    app()->register(new FixtureHookPointProvider(app()), true);
});

afterEach(function () {
    AccessTenantFixture::forget();
});

it('resets a tripped plugin back to enabled', function () {
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    $pluginId = PluginSeed::pluginRow('fixture-echo')['id'];

    for ($i = 1; $i <= 5; $i++) {
        app(CircuitBreaker::class)->recordFailure($tenant, $pluginId, "e{$i}");
    }

    expect(PluginSeed::tenantPluginRow($tenant, $pluginId)['status'])->toBe('error');

    app(ResetPluginBreakerHandler::class)->handle(new ResetPluginBreaker('fixture-echo', $tenant));

    expect(PluginSeed::tenantPluginRow($tenant, $pluginId)['status'])->toBe('enabled');
});

it('refuses resetting an enabled row', function () {
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    expect(fn () => app(ResetPluginBreakerHandler::class)->handle(new ResetPluginBreaker('fixture-echo', $tenant)))
        ->toThrow(PluginRefused::class);
});
