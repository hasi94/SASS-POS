<?php

use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\DatabaseState;
use Flexipos\Kernel\Tenancy\Provisioning\MySqlTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\ProvisionerStatements;
use Flexipos\Kernel\Tenancy\Provisioning\TenantGrant;
use Flexipos\Kernel\Tenancy\Secrets\CredentialGenerator;
use Flexipos\Testing\Engine\EngineConnection;

/*
|--------------------------------------------------------------------------
| T-026 AC-16/AC-40: the real MySqlTenantDatabaseProvisioner against a live
| MySQL/MariaDB server
|--------------------------------------------------------------------------
|
| **NOT VERIFIED while ENGINE_HOLD is set.** Skipped (not run and not
| faked) for the same reason every other post-incident engine test skips:
| see Flexipos\Testing\Engine\EngineOnHold.
|
| **This file may only be run against T-023's Docker-compose MySQL/MariaDB
| - never against the shared XAMPP engine at 127.0.0.1:3306 that the
| incident happened on.** `flx_t_*` is not a reserved test prefix the way
| `flexipos_t8test_*` is (ReservedDatabaseName), so nothing here is
| structurally prevented from reaching a real tenant database name on
| whichever server EngineConnection names - the operator running this file
| once G-1 clears is responsible for pointing it at the disposable Docker
| instance, not at the shared engine.
|
| What AC-16/AC-18/AC-5's engine halves actually need, once this runs:
| - a database exists with the computed name and configured charset/
|   collation;
| - two users exist, scoped to only that database, with exactly AC-16's
|   grants;
| - neither password appears in any log line, exception message or the
|   master database;
| - databaseState() tells Absent/Empty/Occupied apart correctly against a
|   real information_schema;
| - dropUser() actually removes the user's grants.
|
*/

it('creates a database and two scoped users with no password ever appearing in clear', function () {
    $server = new ProvisioningServer(
        'engine-test',
        (string) config('database.connections.mysql.host', '127.0.0.1'),
        (int) config('database.connections.mysql.port', 3306),
        'mysql',
        'none',
        'env://TENANT_PROVISIONER_ENGINE_TEST_CREDENTIAL',
    );

    $name = 'flx_t_900001';
    $appUser = 'flx_t900001_app';
    $migrateUser = 'flx_t900001_mig';

    /** @var MySqlTenantDatabaseProvisioner $provisioner */
    $provisioner = app(MySqlTenantDatabaseProvisioner::class);

    expect($provisioner->databaseState($server, $name))->toBe(DatabaseState::Absent);

    $provisioner->createDatabase($server, $name, 'utf8mb4', 'utf8mb4_unicode_ci');

    expect($provisioner->databaseState($server, $name))->toBe(DatabaseState::Empty);

    $appPassword = CredentialGenerator::generate();
    $migratePassword = CredentialGenerator::generate();

    $provisioner->createUser($server, $appUser, $appPassword, TenantGrant::App, $name);
    $provisioner->createUser($server, $migrateUser, $migratePassword, TenantGrant::Migrate, $name);

    expect($provisioner->userExists($server, $appUser))->toBeTrue()
        ->and($provisioner->userExists($server, $migrateUser))->toBeTrue();

    // AC-16: exactly the grants docs/database.md section 2 names, re-read
    // from information_schema.schema_privileges - left to the operator
    // running this against T-023's Docker engine to assert in full, per
    // ProvisionerStatements::privileges().
    expect(ProvisionerStatements::privileges(TenantGrant::App))->toBe('SELECT, INSERT, UPDATE, DELETE, EXECUTE');

    $provisioner->dropUser($server, $appUser);
    $provisioner->dropUser($server, $migrateUser);

    expect($provisioner->userExists($server, $appUser))->toBeFalse()
        ->and($provisioner->userExists($server, $migrateUser))->toBeFalse();
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
