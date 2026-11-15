<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\MigratePlugin;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\PluginSdk\Features\MigratePlugin\MigratePlugin;
use Flexipos\PluginSdk\Features\MigratePlugin\MigratePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 8 - MigratePluginHandler (AC-38; plan Ruling 6, one of the two
| files allowed to name TenantConnectionPurpose)
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

function migratePluginFixtureEchoPath(): string
{
    return __DIR__.'/../../Fixtures/FixtureEcho';
}

/**
 * A master `tenants` row whose id is literally `tenant-a`/`tenant-b` - the
 * identifier `Tenancy::enter()` resolves against the kernel fixture's own
 * mapping, which is keyed by those two strings, not by a random ULID
 * `PluginSeed::tenant()` would otherwise generate.
 */
function migratePluginFixtureTenant(string $tenant): string
{
    if (PluginSeed::connection()->table('tenants')->where('id', $tenant)->exists()) {
        return $tenant;
    }

    PluginSeed::connection()->table('tenants')->insert([
        'id' => $tenant,
        'sequence' => PluginSeed::connection()->table('tenants')->count() + 1,
        'code' => $tenant,
        'slug' => $tenant,
        'name' => 'Shop '.$tenant,
        'status' => 'active',
        'country' => 'LK',
        'currency' => 'LKR',
        'timezone' => 'Asia/Colombo',
        'business_type' => 'retail',
        'created_at' => '2026-01-01 00:00:00.000',
        'updated_at' => '2026-01-01 00:00:00.000',
    ]);

    return $tenant;
}

/**
 * Enable fixture-echo for a tenant by inserting the master row directly
 * (the full EnablePlugin slice is step 9; this test proves only the
 * migration runner's master mirror).
 */
function migratePluginEnableRow(string $tenantId, string $pluginId, string $versionId): void
{
    PluginSeed::connection()->table('tenant_plugins')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $tenantId,
        'plugin_id' => $pluginId,
        'plugin_version_id' => $versionId,
        'status' => 'enabled',
        'schema_version' => 0,
        'created_at' => '2026-01-01 00:00:00.000',
        'updated_at' => '2026-01-01 00:00:00.000',
    ]);
}

it('mirrors schema_version 2 to the master tenant_plugins row (AC-38)', function () {
    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(migratePluginFixtureEchoPath()));
    $tenant = migratePluginFixtureTenant(AccessTenantFixture::TENANTS[0]);
    migratePluginEnableRow($tenant, $registered->pluginId, $registered->versionId);

    $result = app(MigratePluginHandler::class)->handle(new MigratePlugin('fixture-echo'));

    expect($result->applied)->toBe([$tenant => 2])
        ->and($result->failed)->toBe([]);

    $row = PluginSeed::tenantPluginRow($tenant, $registered->pluginId);
    expect($row['schema_version'])->toBe(2);
});

it('walks only the named tenants when --tenant is given', function () {
    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(migratePluginFixtureEchoPath()));
    $tenantA = migratePluginFixtureTenant('tenant-a');
    $tenantB = migratePluginFixtureTenant('tenant-b');
    migratePluginEnableRow($tenantA, $registered->pluginId, $registered->versionId);
    migratePluginEnableRow($tenantB, $registered->pluginId, $registered->versionId);

    $result = app(MigratePluginHandler::class)->handle(new MigratePlugin('fixture-echo', [$tenantA]));

    expect(array_keys($result->applied))->toBe([$tenantA]);
});
