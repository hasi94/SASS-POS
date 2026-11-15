<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * T-008 step 6: rows in the real master schema for the mapping reader to read.
 *
 * A plain file, required by each test that needs it, for the reason
 * packages/kernel/tests/Support/tenancy-fixture.php exists: a bare function
 * declared in whichever test file was collected first makes a single-file run
 * fail and a full run pass (FP-164).
 *
 * This is the first fixture in the project that writes the master schema rather
 * than a configuration array. The connection is the suite's in-memory SQLite
 * master and every helper asserts that from configuration before it migrates -
 * reading configuration cannot open a socket, and an ambient DB_MASTER_DRIVER
 * beats phpunit.xml's <env>, so without the assertion a developer's shell could
 * point these tests at the shared engine, which is on hold.
 *
 * SQLite is a stand-in. The one difference that matters here is that the master
 * stanza names no `foreign_key_constraints` key, so SQLite leaves foreign keys
 * off - which is what lets a mapping row with a server id that matches nothing
 * exist at all. On the engine that row is unrepresentable, and AC-12 asks for
 * the reader's answer to it anyway: a null, never a default.
 */

/**
 * Apply the master tree to the stand-in.
 */
function masterMappingMigrate(): void
{
    expect(config('database.connections.master.driver'))->toBe('sqlite')
        ->and(config('database.connections.master.database'))->toBe(':memory:');

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);
}

/**
 * A 26-character identifier built from a readable stem, so a failure message
 * says which row it was.
 */
function masterMappingId(string $stem): string
{
    return str_pad(substr($stem, 0, 26), 26, 'X');
}

/**
 * One `database_servers` row. Returns its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function masterMappingServer(string $stem = 'server-one', array $overrides = []): string
{
    $id = masterMappingId($stem);

    DB::connection('master')->table('database_servers')->insert(array_merge([
        'id' => $id,
        'code' => substr($stem, 0, 30),
        'name' => 'Fixture server '.$stem,
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
    ], $overrides));

    return $id;
}

/**
 * One `tenant_databases` row. Returns the tenant id it maps.
 *
 * `migrate_username` and `migrate_credential_ref` are always written, because
 * AC-18's whole content is that the serving record does not carry them even
 * when the row does.
 *
 * @param  array<string, mixed>  $overrides
 */
function masterMappingDatabase(string $tenantStem, string $serverId, array $overrides = []): string
{
    $tenantId = masterMappingId($tenantStem);

    DB::connection('master')->table('tenant_databases')->insert(array_merge([
        'tenant_id' => $tenantId,
        'server_id' => $serverId,
        'database_identifier' => substr($tenantStem, 0, 30),
        'database_name' => 'flx_t_'.substr($tenantStem, 0, 10),
        'app_username' => 'flx_app_'.substr($tenantStem, 0, 10),
        'app_credential_ref' => 'env://FIXTURE_TENANT_APP_PASSWORD',
        'migrate_username' => 'flx_mig_'.substr($tenantStem, 0, 10),
        'migrate_credential_ref' => 'env://FIXTURE_TENANT_MIGRATE_PASSWORD',
        'credential_version' => 1,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'status' => 'active',
        'schema_version' => 6,
        'profile' => 'standard',
        'created_at' => '2026-10-15 00:00:00.000',
        'updated_at' => '2026-10-15 00:00:00.000',
    ], $overrides));

    return $tenantId;
}

/**
 * One `tenants` row with no mapping beside it, which is AC-12's second null
 * case: a tenant the platform knows about whose data has nowhere to live yet.
 */
function masterMappingTenantWithoutMapping(string $stem = 'tenant-unmapped'): string
{
    $id = masterMappingId($stem);

    DB::connection('master')->table('tenants')->insert([
        'id' => $id,
        'sequence' => 1,
        'code' => 'TEN000001',
        'slug' => 'tenant-unmapped',
        'name' => 'Unmapped tenant',
        'status' => 'provisioning',
        'country' => 'LK',
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
        'business_type' => 'retail',
        'created_at' => '2026-10-15 00:00:00.000',
        'updated_at' => '2026-10-15 00:00:00.000',
    ]);

    return $id;
}
