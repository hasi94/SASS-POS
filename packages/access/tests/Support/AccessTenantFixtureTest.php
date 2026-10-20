<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Kernel\Tenancy\Exceptions\TenantSchemaBehind;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 5 - the fixture tenant, and the gate that admits it
|--------------------------------------------------------------------------
|
| This file proves the vehicle every step from 6 on rides: two fixture
| tenants whose SQLite databases hold the shipped tenant tree, reachable only
| through Tenancy::enter(), whose mapping rows carry a schema_version the
| serving gate accepts.
|
| **The control is a pair, and that is the point.** With schema_version at 0
| the gate must refuse with TenantSchemaBehind; with it at the top of the tree
| the identical enter() must succeed. A refusal on its own is also what a
| broken fixture produces - a database that was never migrated, a mapping that
| points nowhere - so neither half means anything without the other.
|
| It names no version as a literal. The top of the tree is the number of
| migration files, the catalogue is read from its own committed fixture, and
| the two are asserted equal, so a migration landing without a publish shows
| up here as well as in SchemaCataloguePublishTest.
|
| Nothing in this file can reach the engine: every connection it touches is
| SQLite, and the tenant one is configured by Tenancy from the fixture
| mapping, which names sqlite drivers only. It spawns no child process, so it
| stays in `composer test:fast` and must NOT be added to toolchainGroupFiles().
|
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('gives every fixture tenant a database holding the whole shipped tenant tree', function (string $tenant) {
    tenancy()->enter($tenant);

    $connection = AccessTenantFixture::connectionName();

    expect(DB::connection($connection)->getDriverName())->toBe('sqlite');

    // Collected rather than asserted one at a time, so a failure names every
    // missing table at once instead of the first.
    $missing = array_values(array_filter(
        tenantTreeTables(),
        static fn (string $table): bool => ! Schema::connection($connection)->hasTable($table)
    ));

    expect(tenantTreeTables())->not->toBeEmpty()
        ->and($missing)->toBe([])
        // The tree was applied, not merely created: the migration ledger the
        // runner reads is there with one row per file.
        ->and(DB::connection($connection)->table('migrations')->count())
        ->toBe(AccessTenantFixture::topVersion());
})->with(AccessTenantFixture::TENANTS);

it('refuses a tenant left behind the floor and admits the same tenant at the top of the tree', function () {
    // The negative half. Only the tenant's own version moves; the floor stays
    // where boot() put it, because the floor is what the gate compares against
    // and moving both would prove nothing about either.
    AccessTenantFixture::mapAt(0);

    $refusal = null;

    try {
        tenancy()->enter('tenant-a');
    } catch (TenantSchemaBehind $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->toBeInstanceOf(TenantSchemaBehind::class)
        ->and((string) $refusal?->getMessage())->toContain('tenant-a');

    // The positive half, and it is the same request. Without it a fixture
    // that pointed at no database at all would pass the half above.
    AccessTenantFixture::mapAt(AccessTenantFixture::topVersion());

    tenancy()->enter('tenant-a');

    expect(DB::connection(AccessTenantFixture::connectionName())->table('permissions')->count())->toBe(0);
});

it('keeps the two fixture tenants distinguishable by data after the tree is applied', function () {
    tenancy()->enter('tenant-a');
    $a = tenancyMarker();
    tenancy()->leave();

    tenancy()->enter('tenant-b');
    $b = tenancyMarker();
    tenancy()->leave();

    // The kernel fixture's proof that a query reached one database rather
    // than the other is a row of data, and copying the migrated template over
    // each file would have removed it. It is restored per tenant, so AC-2's
    // cross-tenant control still has something to read.
    expect($a)->toBe('tenant-a')
        ->and($b)->toBe('tenant-b');
});

it('admits the tenants at exactly the version the committed catalogue publishes', function () {
    $catalogue = json_decode(
        (string) file_get_contents(base_path((string) config('schema.catalogue_fixture'))),
        true
    );

    expect($catalogue)->toBeArray();

    /** @var list<array{version: int}> $catalogue */
    $versions = array_map(static fn (array $row): int => $row['version'], $catalogue);

    expect($versions)->not->toBeEmpty()
        // The fixture's floor is the catalogue's top, so a migration shipped
        // without a publish - or a publish without the migration - stops the
        // fixture rather than silently serving tenants at the wrong version.
        ->and(AccessTenantFixture::topVersion())->toBe(max($versions))
        ->and((int) config('tenancy.required_schema_version'))->toBe(AccessTenantFixture::topVersion());
});

it('enforces the tree foreign keys on a tenant entered through the fixture', function () {
    AccessTenantFixture::enter('tenant-a');

    $connection = DB::connection(AccessTenantFixture::connectionName());
    $role = str_pad('ROLE-FK', 26, '0');

    $connection->table('roles')->insert([
        'id' => $role,
        'key' => 'fk-probe',
        'name' => 'FK probe',
        'is_system' => false,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);

    // The refusal half: a grant of a permission that does not exist.
    expect(fn () => $connection->table('role_permissions')->insert([
        'role_id' => $role,
        'permission_key' => 'nothing.declared',
    ]))->toThrow(QueryException::class);

    // The liveness half, and it is not optional: a connection on which every
    // insert fails would satisfy the half above.
    $connection->table('permissions')->insert([
        'key' => 'fixture.real',
        'module' => 'fixture',
        'group' => 'fixture',
        'description' => 'A declared key.',
        'is_branch_scoped' => false,
    ]);

    $connection->table('role_permissions')->insert([
        'role_id' => $role,
        'permission_key' => 'fixture.real',
    ]);

    expect($connection->table('role_permissions')->count())->toBe(1);
});

it('records that a tenant entered without the fixture helper enforces nothing', function () {
    // Measured, and the reason enter() above exists. The tenant stanza
    // Tenancy writes carries no foreign_key_constraints key - it has no
    // reason to, since MySQL and MariaDB enforce regardless - so SQLite
    // parses every REFERENCES clause in the tree and ignores it. Asserted
    // rather than described, so that the day the stanza or the driver
    // changes, this case fails and the helper's docblock stops being true
    // quietly.
    // Through the fixture's named lax door rather than `tenancy()->enter()`
    // directly: step 24's sweep forbids the latter in this task's tests,
    // and this case is the one place the laxness is the subject rather
    // than an accident.
    AccessTenantFixture::enterWithoutEnforcement('tenant-a');

    $connection = DB::connection(AccessTenantFixture::connectionName());
    $role = str_pad('ROLE-LAX', 26, '0');

    $connection->table('roles')->insert([
        'id' => $role,
        'key' => 'lax-probe',
        'name' => 'Lax probe',
        'is_system' => false,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);

    $connection->table('role_permissions')->insert([
        'role_id' => $role,
        'permission_key' => 'nothing.declared',
    ]);

    expect($connection->table('role_permissions')->count())->toBe(1)
        ->and($connection->table('permissions')->where('key', 'nothing.declared')->count())->toBe(0);
});
