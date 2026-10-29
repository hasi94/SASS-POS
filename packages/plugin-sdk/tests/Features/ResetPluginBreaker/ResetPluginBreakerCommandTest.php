<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\ResetPluginBreaker;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\PluginSdk\Breaker\CircuitBreaker;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

require_once __DIR__.'/../../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 11 - plugin:reset
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

it('exits 0 for a tripped plugin, and 1 when it is not tripped', function () {
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    $pluginId = PluginSeed::pluginRow('fixture-echo')['id'];

    for ($i = 1; $i <= 5; $i++) {
        app(CircuitBreaker::class)->recordFailure($tenant, $pluginId, "e{$i}");
    }

    $exitCode = Artisan::call('plugin:reset', ['plugin' => 'fixture-echo', '--tenant' => $tenant]);
    expect($exitCode)->toBe(0);

    $exitCode = Artisan::call('plugin:reset', ['plugin' => 'fixture-echo', '--tenant' => $tenant]);
    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('notTripped');
});
