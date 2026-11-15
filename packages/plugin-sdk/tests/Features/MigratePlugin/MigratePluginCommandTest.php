<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\MigratePlugin;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 8 - plugin:migrate
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

it('exits 0 and reports the schema version reached', function () {
    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));

    PluginSeed::connection()->table('tenants')->insert([
        'id' => 'tenant-a', 'sequence' => 1, 'code' => 'tenant-a', 'slug' => 'tenant-a', 'name' => 'Shop',
        'status' => 'active', 'country' => 'LK', 'currency' => 'LKR', 'timezone' => 'Asia/Colombo',
        'business_type' => 'retail', 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000',
    ]);

    PluginSeed::connection()->table('tenant_plugins')->insert([
        'id' => (string) Str::ulid(), 'tenant_id' => 'tenant-a', 'plugin_id' => $registered->pluginId,
        'plugin_version_id' => $registered->versionId, 'status' => 'enabled', 'schema_version' => 0,
        'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000',
    ]);

    $exitCode = Artisan::call('plugin:migrate', ['plugin' => 'fixture-echo']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('tenant-a: schema_version 2');
});
