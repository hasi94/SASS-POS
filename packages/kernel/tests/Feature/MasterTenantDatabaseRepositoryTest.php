<?php

use Flexipos\Kernel\Tenancy\CachingTenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\InMemoryTenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\MasterTenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * T-008 step 6, AC-12: the mapping comes from the master schema, as one joined
 * read, and an answer that is not a mapping is a null rather than a default.
 *
 * AC-12 is an [E] criterion. This file is **not** its evidence: the engine half
 * is the same read against MariaDB, and while ENGINE_HOLD is set it is NOT
 * VERIFIED, waiting on gate G-1 (AC-63, D-35). What is answered here is the
 * query, the mapping of columns onto the record, and the four refusals - all of
 * which are the same code on either engine, and none of which needs one.
 */

require_once __DIR__.'/../Support/master-mapping-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    masterMappingMigrate();
});

/*
|--------------------------------------------------------------------------
| The shipped configuration, with no test override
|--------------------------------------------------------------------------
*/

it('binds the master-backed reader behind the cache by default', function () {
    // AC-12 asks for the shipped configuration with no test override, and the
    // suite does override it: phpunit.xml names the local stand-in, for the
    // same reason it points master at SQLite - T-003's and T-007's isolation
    // proofs predate the master schema and must not need seventeen migrations
    // to resolve two fixture tenants.
    //
    // So the shipped default is read from the shipped file: the environment
    // variable is removed and config/tenancy.php is evaluated, which is the
    // value a deployment gets. Then the source is selected explicitly, which is
    // what the rest of this file reads through.
    // The variable is removed from all three places Laravel's Env repository
    // reads - $_SERVER, $_ENV and the process environment - and restored in a
    // finally, because phpunit.xml's <env> writes only two of them and leaving
    // it unset here is what made every later tenancy test resolve the master
    // reader instead of the stand-in.
    $ambient = null;

    foreach ([$_SERVER['TENANCY_MAPPING_SOURCE'] ?? null, $_ENV['TENANCY_MAPPING_SOURCE'] ?? null, getenv('TENANCY_MAPPING_SOURCE')] as $value) {
        if (is_string($value) && $ambient === null) {
            $ambient = $value;
        }
    }

    unset($_ENV['TENANCY_MAPPING_SOURCE'], $_SERVER['TENANCY_MAPPING_SOURCE']);
    putenv('TENANCY_MAPPING_SOURCE');

    try {
        $shipped = require base_path('config/tenancy.php');
    } finally {
        if (is_string($ambient)) {
            $_ENV['TENANCY_MAPPING_SOURCE'] = $ambient;
            $_SERVER['TENANCY_MAPPING_SOURCE'] = $ambient;
            putenv('TENANCY_MAPPING_SOURCE='.$ambient);
        }
    }

    expect($shipped['mapping']['source'])->toBe('master')
        ->and($shipped['mapping']['ttl'])->toBe(300);

    config()->set('tenancy.mapping.source', 'master');
    app()->forgetInstance(TenantDatabaseRepository::class);

    $repository = app(TenantDatabaseRepository::class);

    expect($repository)->toBeInstanceOf(CachingTenantDatabaseRepository::class)
        ->and($repository->inner())->toBeInstanceOf(MasterTenantDatabaseRepository::class);

    // The local stand-in is still reachable, which is what keeps T-003's and
    // T-007's fixture path - a configuration array and no query - working
    // unchanged.
    config()->set('tenancy.mapping.source', 'config');
    app()->forgetInstance(TenantDatabaseRepository::class);

    expect(app(TenantDatabaseRepository::class))->toBeInstanceOf(InMemoryTenantDatabaseRepository::class);
});

/*
|--------------------------------------------------------------------------
| One joined read, mapped onto the record
|--------------------------------------------------------------------------
*/

it('reads the server row and the mapping row as one record', function () {
    $server = masterMappingServer();
    $tenantId = masterMappingDatabase('tenant-alpha', $server);

    $queries = [];
    DB::connection('master')->listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $record = app(MasterTenantDatabaseRepository::class)->find($tenantId);

    expect($record)->toBeInstanceOf(TenantDatabase::class);

    // Every field AC-12 names, by value, so a reader that silently defaulted a
    // column would be reported rather than merely suspected.
    expect($record?->tenantId)->toBe($tenantId)
        ->and($record?->driver)->toBe('mariadb')
        ->and($record?->host)->toBe('db-fixture-host')
        ->and($record?->port)->toBe(3307)
        ->and($record?->database)->toBe('flx_t_tenant-alp')
        ->and($record?->username)->toBe('flx_app_tenant-alp')
        ->and($record?->credentialRef)->toBe('env://FIXTURE_TENANT_APP_PASSWORD')
        ->and($record?->status)->toBe('active')
        ->and($record?->schemaVersion)->toBe(6)
        ->and($record?->credentialVersion)->toBe(1);

    // One read, not two: the join is the point. A reader that fetched the
    // mapping and then the server would answer identically and cost twice as
    // much per request.
    expect($queries)->toHaveCount(1);

    // AC-18's control object is the runner's own type; the serving record has
    // no migrate pair at all, even though the row it was read from does.
    expect(DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->value('migrate_username'))
        ->toBe('flx_mig_tenant-alp');

    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(TenantDatabase::class))->getProperties()
    );

    expect($properties)->not->toContain('migrateUsername');
    expect($properties)->not->toContain('migrateCredentialRef');
});

/*
|--------------------------------------------------------------------------
| Three ways to have no mapping, and all of them are null
|--------------------------------------------------------------------------
*/

it('returns null for an unknown tenant, an unmapped tenant and a mapping whose server row is gone', function () {
    $server = masterMappingServer();
    $mapped = masterMappingDatabase('tenant-mapped', $server);
    $unmapped = masterMappingTenantWithoutMapping();

    // A mapping row pointing at a server row that does not exist. On the engine
    // the foreign key makes this unrepresentable; the stand-in has foreign keys
    // off, which is what lets the reader's answer to it be asserted at all.
    $orphan = masterMappingDatabase('tenant-orphan', masterMappingId('no-such-server'));

    $repository = app(MasterTenantDatabaseRepository::class);

    expect($repository->find('nobody-has-ever-heard-of-this'))->toBeNull()
        ->and($repository->find($unmapped))->toBeNull()
        ->and($repository->find($orphan))->toBeNull()
        // The empty identifier, which Tenancy refuses before it ever gets here,
        // must still not match a row by accident.
        ->and($repository->find(''))->toBeNull();

    // The control for all four: the mapped tenant is found, so "null" is an
    // answer about the data rather than what this reader always says.
    expect($repository->find($mapped))->toBeInstanceOf(TenantDatabase::class);
});

it('does not fall back to a default when a column is missing', function () {
    $server = masterMappingServer();

    // A status nothing serves and a schema version below any baseline: the
    // reader reports them as they are. Deciding what they mean is the status
    // gate's and the version gate's job, and a reader that corrected them here
    // would make both gates untestable.
    $tenantId = masterMappingDatabase('tenant-suspended', $server, [
        'status' => 'suspended',
        'schema_version' => 0,
        'credential_version' => 9,
    ]);

    $record = app(MasterTenantDatabaseRepository::class)->find($tenantId);

    expect($record?->status)->toBe('suspended')
        ->and($record?->isActive())->toBeFalse()
        ->and($record?->schemaVersion)->toBe(0)
        ->and($record?->credentialVersion)->toBe(9);
});
