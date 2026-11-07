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
| whichever server it is pointed at.
|
| **Review round 1 W-5: the server connection is built from Docker-only
| settings, never from `database.connections.mysql.*`** (that connection's
| own shipped default is the shared XAMPP server, exactly the thing
| ENGINE_HOLD protects). `tenancy.provisioning.engine_test_host`/`_port`
| default to `127.0.0.1`/`13306` - this project's docker-compose.yml's own
| published MySQL port, never XAMPP's `3306`. {@see assertNotTheXamppEngine()}
| refuses outright, before any connection is attempted, if the resolved
| host:port is `127.0.0.1:3306` regardless of how it got there (an
| operator's own override included) - the one thing a comment alone cannot
| enforce.
|
| **Cleanup so a second run doesn't fail its own precondition.** The
| database/user names are suffixed with a fresh random token per run,
| rather than the earlier fixed `flx_t_900001` - a leftover database from a
| previous run (this file never drops a database; only
| `Flexipos\Testing\Engine\EngineDatabase` may, and its own reserved prefix
| is `flexipos_t8test_*`, not this engine test's) can never collide with
| the current run's own fresh name. The two users this test creates are
| still dropped at the end either way.
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

/**
 * @throws RuntimeException the resolved host:port is the shared XAMPP
 *                          engine's own default - this test must never
 *                          connect to it, under any configuration
 */
function assertNotTheXamppEngine(string $host, int $port): void
{
    if ($host === '127.0.0.1' && $port === 3306) {
        throw new RuntimeException(
            'TenantProvisionerEngineTest refuses to run against 127.0.0.1:3306 - that is the shared XAMPP '
            .'engine ENGINE_HOLD protects, never T-023\'s disposable Docker instance. Point '
            .'tenancy.provisioning.engine_test_host/engine_test_port at the Docker-compose MySQL port instead.'
        );
    }
}

it('refuses outright to run against the shared XAMPP engine default, with no connection attempted (review round 1 W-5)', function () {
    expect(fn () => assertNotTheXamppEngine('127.0.0.1', 3306))->toThrow(RuntimeException::class, 'XAMPP');

    // The control: the Docker-compose port, and a different host entirely,
    // both pass.
    assertNotTheXamppEngine('127.0.0.1', 13306);
    assertNotTheXamppEngine('mysql-docker-host', 3306);
});

it('creates a database and two scoped users with no password ever appearing in clear', function () {
    $host = (string) config('tenancy.provisioning.engine_test_host', '127.0.0.1');
    $port = (int) config('tenancy.provisioning.engine_test_port', 13306);

    assertNotTheXamppEngine($host, $port);

    $server = new ProvisioningServer(
        'engine-test',
        $host,
        $port,
        'mysql',
        'none',
        'env://TENANT_PROVISIONER_ENGINE_TEST_CREDENTIAL',
    );

    $suffix = substr(bin2hex(random_bytes(4)), 0, 6);
    $name = 'flx_t_9'.$suffix;
    $appUser = 'flx_t9'.$suffix.'_app';
    $migrateUser = 'flx_t9'.$suffix.'_mig';

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
