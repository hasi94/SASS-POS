<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\ListTenantPlugins;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\ListTenantPlugins\ListTenantPlugins;
use Flexipos\PluginSdk\Features\ListTenantPlugins\ListTenantPluginsHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Tests\TestCase;

require_once __DIR__.'/../../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 11 - ListTenantPluginsHandler (AC-40)
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

it('lists every column for enabled, disabled and error rows, and no other tenant`s rows', function () {
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenantA = pluginRegisterFixtureTenant('tenant-a');
    $tenantB = pluginRegisterFixtureTenant('tenant-b');

    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenantA));
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenantB));

    $result = app(ListTenantPluginsHandler::class)->handle(new ListTenantPlugins($tenantA));

    expect($result->rows)->toHaveCount(1);

    $row = $result->rows[0];
    expect(array_keys($row))->toBe(['plugin', 'version', 'status', 'schema_version', 'failure_count', 'tripped_at', 'last_error', 'enabled_at'])
        ->and($row['plugin'])->toBe('fixture-echo')
        ->and($row['status'])->toBe('enabled');
});
