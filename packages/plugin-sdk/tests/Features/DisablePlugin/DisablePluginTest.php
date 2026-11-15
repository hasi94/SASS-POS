<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\DisablePlugin;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Features\DisablePlugin\DisablePlugin;
use Flexipos\PluginSdk\Features\DisablePlugin\DisablePluginHandler;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 9 - DisablePluginHandler (AC-15)
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

it('sets status disabled and stamps disabled_at, leaving the tables in place (AC-15)', function () {
    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    $result = app(DisablePluginHandler::class)->handle(new DisablePlugin('fixture-echo', $tenant));

    expect($result->noop)->toBeFalse();

    $row = PluginSeed::tenantPluginRow($tenant, $registered->pluginId);
    expect($row['status'])->toBe('disabled')
        ->and($row['disabled_at'])->not->toBeNull();

    AccessTenantFixture::enter('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->getSchemaBuilder()->hasTable('plg_fixture_echo_after_effects'))->toBeTrue();
});

it('re-enabling returns to enabled without re-running migrations', function () {
    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));
    app(DisablePluginHandler::class)->handle(new DisablePlugin('fixture-echo', $tenant));

    $result = app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    expect($result->status)->toBe('enabled');

    AccessTenantFixture::enter('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->table('plugin_migrations')->where('plugin_name', 'fixture-echo')->count())->toBe(2);
});

it('is a no-op on an already-disabled row', function () {
    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));
    app(DisablePluginHandler::class)->handle(new DisablePlugin('fixture-echo', $tenant));

    $result = app(DisablePluginHandler::class)->handle(new DisablePlugin('fixture-echo', $tenant));

    expect($result->noop)->toBeTrue();
});

it('refuses disabling a row that is in error', function () {
    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    PluginSeed::connection()->table('tenant_plugins')
        ->where('tenant_id', $tenant)->where('plugin_id', $registered->pluginId)
        ->update(['status' => 'error']);

    try {
        app(DisablePluginHandler::class)->handle(new DisablePlugin('fixture-echo', $tenant));
        throw new \RuntimeException('Expected PluginRefused.');
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('cannotDisable');
    }
});
