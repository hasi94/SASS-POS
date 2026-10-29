<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantFleet;
use Flexipos\Kernel\Tenancy\Exceptions\MappingSourceNotPermitted;
use Flexipos\Kernel\Tenancy\InMemoryTenantFleet;
use Flexipos\Kernel\Tenancy\MappingSource;
use Flexipos\Kernel\Tenancy\MasterTenantFleet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 7 - which tenants the archival job visits (Ruling 6)
|--------------------------------------------------------------------------
|
| TenantFleet::servingTenantIds() answers exactly TenantConnectionPurpose::
| SERVING_STATUSES - today, exactly `active` - on both mapping sources, by
| the same MappingSource switch TenantDatabaseRepository already uses.
*/

uses(TestCase::class);

function tenantFleetId(string $stem): string
{
    return str_pad(substr($stem, 0, 26), 26, 'X');
}

function tenantFleetInEnvironment(string $environment, Closure $case): void
{
    $ambient = app()->environment();

    app()->instance('env', $environment);
    app()->forgetInstance(TenantFleet::class);

    try {
        $case();
    } finally {
        app()->instance('env', $ambient);
        app()->forgetInstance(TenantFleet::class);
    }
}

it('on the master source, returns exactly the active tenants, sorted', function () {
    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);

    $server = tenantFleetId('fleet-server');

    DB::connection('master')->table('database_servers')->insert([
        'id' => $server,
        'code' => 'fleet-server',
        'name' => 'Fleet server',
        'host' => 'db-fixture-host',
        'port' => 3307,
        'engine' => 'mariadb',
        'tls_mode' => 'disabled',
        'provisioner_credential_ref' => 'env://FIXTURE_PROVISIONER_PASSWORD',
        'tier' => 'shared',
        'max_tenants' => 50,
        'status' => 'active',
        'created_at' => '2026-10-15 00:00:00.000',
        'updated_at' => '2026-10-15 00:00:00.000',
    ]);

    $statuses = ['active', 'read_only', 'suspended', 'provisioning', 'archived'];

    foreach ($statuses as $status) {
        DB::connection('master')->table('tenant_databases')->insert([
            'tenant_id' => tenantFleetId('fleet-'.$status),
            'server_id' => $server,
            'database_identifier' => $status,
            'database_name' => 'flx_t_'.$status,
            'app_username' => 'flx_app_'.$status,
            'app_credential_ref' => 'env://FIXTURE_TENANT_APP_PASSWORD',
            'migrate_username' => 'flx_mig_'.$status,
            'migrate_credential_ref' => 'env://FIXTURE_TENANT_MIGRATE_PASSWORD',
            'credential_version' => 1,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'status' => $status,
            'schema_version' => 1,
            'profile' => 'standard',
            'created_at' => '2026-10-15 00:00:00.000',
            'updated_at' => '2026-10-15 00:00:00.000',
        ]);
    }

    $fleet = new MasterTenantFleet(app('db'), app('config'));

    expect($fleet->servingTenantIds())->toBe([tenantFleetId('fleet-active')]);
});

it('on the config source, returns exactly the active tenants from tenancy.databases', function () {
    config()->set('tenancy.databases', [
        'tenant-active-a' => ['status' => 'active'],
        'tenant-active-b' => ['status' => 'active'],
        'tenant-read-only' => ['status' => 'read_only'],
        'tenant-suspended' => ['status' => 'suspended'],
        'tenant-provisioning' => ['status' => 'provisioning'],
        'tenant-archived' => ['status' => 'archived'],
    ]);

    $fleet = new InMemoryTenantFleet(app('config'));

    expect($fleet->servingTenantIds())->toBe(['tenant-active-a', 'tenant-active-b']);
});

it('resolves to the config stand-in in local development and refuses it outside local development, through MappingSource', function () {
    config()->set(MappingSource::KEY, MappingSource::CONFIG);
    config()->set('tenancy.databases', ['tenant-active-a' => ['status' => 'active']]);

    tenantFleetInEnvironment('testing', function () {
        expect(app(TenantFleet::class))->toBeInstanceOf(InMemoryTenantFleet::class);
    });

    tenantFleetInEnvironment('production', function () {
        expect(fn () => app(TenantFleet::class))
            ->toThrow(MappingSourceNotPermitted::class, 'refused in the [production] environment');
    });
});

it('resolves to the master source when the key is master, in every environment', function () {
    config()->set(MappingSource::KEY, MappingSource::MASTER);

    tenantFleetInEnvironment('production', function () {
        expect(app(TenantFleet::class))->toBeInstanceOf(MasterTenantFleet::class);
    });
});
