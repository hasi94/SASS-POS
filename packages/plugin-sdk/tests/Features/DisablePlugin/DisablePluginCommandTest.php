<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\DisablePlugin;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
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
| T-019 step 9 - plugin:disable (AC-42)
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

it('exits 0 and reports the disabled state', function () {
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    $exitCode = Artisan::call('plugin:disable', ['plugin' => 'fixture-echo', '--tenant' => $tenant]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Disabled fixture-echo');
});
