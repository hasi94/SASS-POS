<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryResult;
use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 - the connection name has one authority, proved by moving it
|--------------------------------------------------------------------------
|
| `Support\TenantConnection` is declared to be this package's single home for
| `config('tenancy.connection')`, and step 6's registry sync and step 9's
| resolver were folded onto it. Both folds are unobservable without this
| file: a private copy of the same four lines reads the same key and returns
| the same connection, so a test that agrees with the shipped name cannot
| tell the fold from the duplicate it replaced. Only a moved value can.
|
| **The move has to be made before the tenant is entered, and that is the
| kernel's rule rather than a convenience here.** Whatever name
| `tenancy.connection` holds is the name `TenantConnectionFactory` guards:
| a connection under that name must be a stanza `Tenancy` itself wrote for
| the resolved tenant, or it is refused. So the key is moved first and
| `Tenancy::enter()` then writes the real tenant's stanza under the moved
| name. Pointing the key at a scratch database while a tenant is resolved is
| refused by T-009's guard - measured, not assumed.
|
| **The negative control is the shipped name, left configured and pointing
| somewhere else.** Before the move, `database.connections.tenant` is
| overwritten with a second migrated database that belongs to no tenant.
| It is never named by anything: `tenancy.connection` does not hold it and
| no stanza Tenancy wrote carries it. A consumer that kept the literal
| `tenant` does not fail loudly on it either - the factory sees a name that
| is not the configured one and a stanza it does not vouch for, hands back a
| plain connection, and the write lands there silently. That is the failure
| this file exists to catch, which is why the decoy is a *writable* database
| and not an absent stanza.
|
| Each case therefore asserts three things: the keys arrived in the database
| the moved name points at, the decoy is empty, and - the probe's own
| liveness control - the decoy answers with a row the case puts there by
| hand, so "empty" is the reading of a live table rather than of nothing.
|
*/

uses(TestCase::class);

/**
 * The connection name the application ships with, latched before any case
 * moves it.
 */
function tcAuthorityShippedConnection(): string
{
    static $name = null;

    if ($name === null) {
        $name = (string) config('tenancy.connection', 'tenant');
    }

    return $name;
}

function tcAuthorityDirectory(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'tenant-connection-authority');
}

/**
 * Point the shipped connection name at a migrated database that is nobody's
 * tenant - the unused second value.
 *
 * It holds the shipped tenant tree, so every table a consumer could write to
 * exists and a misdirected write succeeds instead of erroring, and it is
 * mapped to no tenant, so reaching it is not reaching anybody's data.
 */
function tcAuthorityInstallDecoy(): void
{
    $directory = tcAuthorityDirectory();

    File::ensureDirectoryExists($directory);

    $path = $directory.DIRECTORY_SEPARATOR.'decoy.sqlite';

    AccessTenantFixture::copyTemplateTo('tenant-b', $path);

    config()->set('database.connections.'.tcAuthorityShippedConnection(), [
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge(tcAuthorityShippedConnection());
}

/**
 * Move the connection name, then enter a fixture tenant under it.
 *
 * In this order on purpose: `Tenancy::enter()` writes its stanza under
 * whatever name the key holds, so moving the key first is what makes the
 * moved name the real, vouched-for tenant connection.
 */
function tcAuthorityEnterAs(string $connection): void
{
    config()->set('tenancy.connection', $connection);

    AccessTenantFixture::enter('tenant-a');
}

/**
 * The `permissions` keys a named connection holds, sorted.
 *
 * @return list<string>
 */
function tcAuthorityKeys(string $connection): array
{
    $keys = [];

    foreach (DB::connection($connection)->table('permissions')->pluck('key') as $key) {
        $keys[] = (string) $key;
    }

    sort($keys);

    return $keys;
}

/**
 * Show that the decoy is a live, writable, readable database, so the
 * emptiness asserted beside this call is a reading rather than a silence.
 */
function tcAuthorityAssertDecoyIsLive(): void
{
    DB::connection(tcAuthorityShippedConnection())->table('permissions')->insert([
        'key' => 'probe.decoy_is_live',
        'module' => 'probe',
        'group' => 'probe',
        'description' => 'Written by the test, to prove the empty reading above was a reading.',
        'is_branch_scoped' => false,
    ]);

    expect(tcAuthorityKeys(tcAuthorityShippedConnection()))->toBe(['probe.decoy_is_live']);
}

/**
 * The keys the application declares, read from the registry rather than
 * listed here - six today and longer the day any module declares one.
 *
 * @return list<string>
 */
function tcAuthorityRegistryKeys(): array
{
    /** @var PermissionRegistry $registry */
    $registry = app(PermissionRegistry::class);

    $keys = $registry->keys();

    sort($keys);

    return $keys;
}

function tcAuthorityRunSync(): SyncPermissionRegistryResult
{
    /** @var SyncPermissionRegistryHandler $handler */
    $handler = app(SyncPermissionRegistryHandler::class);

    return $handler->handle(new SyncPermissionRegistry);
}

beforeEach(function () {
    tcAuthorityShippedConnection();

    AccessTenantFixture::boot();

    tcAuthorityInstallDecoy();
});

afterEach(function () {
    // The fixture's teardown purges the stanza `tenancy.connection` names,
    // so it runs while the moved name is still in place; the key goes back
    // afterwards so this file's last case cannot decide what the next file
    // sees.
    AccessTenantFixture::forget();

    config()->set('tenancy.connection', tcAuthorityShippedConnection());

    DB::purge(tcAuthorityShippedConnection());

    File::deleteDirectory(tcAuthorityDirectory());
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('writes the registry sync through the connection the configured name points at', function () {
    tcAuthorityEnterAs('tc_authority_moved');

    $result = tcAuthorityRunSync();

    $declared = tcAuthorityRegistryKeys();

    expect($declared)->not->toBeEmpty()
        ->and($result->inserted)->not->toBeEmpty()
        ->and(tcAuthorityKeys('tc_authority_moved'))->toBe($declared);

    // The unused second value, configured and writable and named by nothing.
    // A consumer holding the literal `tenant` writes here.
    expect(tcAuthorityKeys(tcAuthorityShippedConnection()))->toBe([]);

    tcAuthorityAssertDecoyIsLive();
});

it('follows the name to a second value, so neither name is the one that happens to work', function () {
    tcAuthorityEnterAs('tc_authority_second');

    tcAuthorityRunSync();

    expect(tcAuthorityKeys('tc_authority_second'))->toBe(tcAuthorityRegistryKeys())
        ->and(tcAuthorityKeys(tcAuthorityShippedConnection()))->toBe([]);

    tcAuthorityAssertDecoyIsLive();
});

it('reads the resolver through the same authority', function () {
    tcAuthorityEnterAs('tc_authority_moved');

    tcAuthorityRunSync();

    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    $employeeId = (string) Str::ulid();

    // The sync registered the keys in the moved database and in no other, so
    // the resolver's own answer says which database it read: a registered
    // key with no such employee is `employee_unavailable`, and the decoy -
    // asserted empty below - would make the identical question
    // `unknown_permission`, because that refusal is decided first.
    expect($resolver->decide($employeeId, 'employees.view', null)->reason)
        ->toBe(RefusalReason::EmployeeUnavailable);

    // The liveness half of the pair: the resolver can produce the other
    // answer, so the one above is a discrimination and not a constant.
    expect($resolver->decide($employeeId, 'employees.nonexistent_key', null)->reason)
        ->toBe(RefusalReason::UnknownPermission);

    expect(tcAuthorityKeys(tcAuthorityShippedConnection()))->toBe([]);

    tcAuthorityAssertDecoyIsLive();
});
