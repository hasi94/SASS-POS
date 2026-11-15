<?php

use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\DatabaseState;
use Flexipos\Kernel\Tenancy\Provisioning\MySqlTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\ProvisionerStatements;
use Flexipos\Kernel\Tenancy\Provisioning\TenantDatabaseNames;
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
| port is `3306` on any loopback host - `127.0.0.0/8`, `localhost`, `::1`
| - regardless of how it got there (an operator's own override included).
|
| **Review round 2 W-5.** Two gaps in round 1's own fix, both closed here:
| (a) `assertNotTheXamppEngine()` previously refused only the literal
| `127.0.0.1:3306`, letting `localhost:3306`/`::1:3306`/`127.0.0.2:3306`
| through - now any loopback address on port 3306 is refused, matching
| this docblock's own original claim rather than its narrower code; (b) the
| random database-name suffix below was hex, which would fail
| `TenantDatabaseNames::assertDatabaseName()`'s own `[0-9]{6,}` requirement
| the moment `ENGINE_HOLD` lifts - fails safe today (skipped), but wrong -
| so it is digits only now.
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
 * Review round 2 W-5: any loopback address, not only the literal
 * `127.0.0.1` - `127.0.0.0/8`, `localhost`, and `::1` all resolve to the
 * same machine, and the shared XAMPP engine answers on all of them.
 */
function isLoopbackHost(string $host): bool
{
    if ($host === 'localhost' || $host === '::1') {
        return true;
    }

    return preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1;
}

/**
 * @throws RuntimeException the resolved host:port is the shared XAMPP
 *                          engine's own default (any loopback address,
 *                          port 3306) - this test must never connect to
 *                          it, under any configuration
 */
function assertNotTheXamppEngine(string $host, int $port): void
{
    if ($port === 3306 && isLoopbackHost($host)) {
        throw new RuntimeException(
            'TenantProvisionerEngineTest refuses to run against port 3306 on a loopback host ['.$host.'] - '
            .'that is the shared XAMPP engine ENGINE_HOLD protects, never T-023\'s disposable Docker instance. '
            .'Point tenancy.provisioning.engine_test_host/engine_test_port at the Docker-compose MySQL port instead.'
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

it('refuses every loopback spelling on port 3306, not only the literal 127.0.0.1 (review round 2 W-5)', function () {
    expect(fn () => assertNotTheXamppEngine('localhost', 3306))->toThrow(RuntimeException::class, 'XAMPP')
        ->and(fn () => assertNotTheXamppEngine('::1', 3306))->toThrow(RuntimeException::class, 'XAMPP')
        ->and(fn () => assertNotTheXamppEngine('127.0.0.2', 3306))->toThrow(RuntimeException::class, 'XAMPP')
        ->and(fn () => assertNotTheXamppEngine('127.255.255.255', 3306))->toThrow(RuntimeException::class, 'XAMPP');

    // Still not refused: a loopback host on a non-3306 port, or a real
    // (non-loopback) host on 3306.
    assertNotTheXamppEngine('localhost', 13306);
    assertNotTheXamppEngine('mysql-docker-host', 3306);
});

it('generates a digits-only suffix that TenantDatabaseNames itself accepts (review round 2 W-5)', function () {
    // Not gated on ENGINE_HOLD: this only proves the naming shape is still
    // legal, which needs no live server - the actual defect (a hex suffix)
    // would only have surfaced once ENGINE_HOLD lifted and this test
    // stopped skipping, which is exactly what this assertion catches now
    // instead.
    $suffix = '9'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    $name = 'flx_t_'.$suffix;
    $appUser = 'flx_t'.$suffix.'_app';
    $migrateUser = 'flx_t'.$suffix.'_mig';

    expect(fn () => TenantDatabaseNames::assertDatabaseName($name))
        ->not->toThrow(Throwable::class);
    expect(fn () => TenantDatabaseNames::assertUsername($appUser))
        ->not->toThrow(Throwable::class);
    expect(fn () => TenantDatabaseNames::assertUsername($migrateUser))
        ->not->toThrow(Throwable::class);
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

    // Digits only (review round 2 W-5): TenantDatabaseNames::assertDatabaseName()
    // requires `[0-9]{6,}` after the prefix - a hex suffix would fail that
    // check the moment ENGINE_HOLD lifts. The leading '9' keeps this run's
    // name well clear of any real tenant's sequence.
    $suffix = '9'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    $name = 'flx_t_'.$suffix;
    $appUser = 'flx_t'.$suffix.'_app';
    $migrateUser = 'flx_t'.$suffix.'_mig';

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
