<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\SyncPermissionRegistry;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryResult;
use Flexipos\Access\Registry\AccessPermissions;
use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Access\Tests\Support\FixturePermissionProvider;
use Flexipos\Access\Tests\Support\SecondFixturePermissionProvider;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 6 - the registry sync
|--------------------------------------------------------------------------
|
| AC-15 (the four counts, idempotence, the refused scope flip), AC-16 (an
| orphan is reported and never deleted) and AC-18's sweep (the table's keys
| equal the registry's keys, enumerated from the registry and not from a list
| here).
|
| Every case runs against step 5's fixture tenant: a real SQLite database
| holding the shipped tenant tree, entered through Tenancy. Nothing here
| builds a tenant connection and nothing reaches an engine.
|
| Three shapes are deliberate and a reviewer should not read any of them as
| incidental:
|
| - idempotence is asserted on the ROWS, not on the counts. A second sync
|   reporting "inserted 0" is also what a sync that silently rewrote every
|   row with identical values reports, and the thing AC-15 cares about is
|   that nothing moved - including perm_version, which is why an employee
|   exists in that case at all;
| - the scope-flip case carries a second, undeclared-yet key, because
|   "refused for that key and the rest proceeds" is two facts and the second
|   one is the one a guard written as an early return would break;
| - the whole-sync refusal is paired with the identical sync minus the
|   conflict. A table that stayed empty is also what a sync that cannot
|   reach the database produces.
|
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();

    // enter(), not tenancy()->enter(): the fixture helper turns SQLite's
    // foreign keys on, which the tenant stanza does not (see its docblock).
    // Without it the orphan case below would be proving nothing about the
    // handler that a laxer database was not already allowing.
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function syncTenant(): Connection
{
    /** @var Connection $connection */
    $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

    return $connection;
}

function runSync(): SyncPermissionRegistryResult
{
    /** @var SyncPermissionRegistryHandler $handler */
    $handler = app(SyncPermissionRegistryHandler::class);

    return $handler->handle(new SyncPermissionRegistry);
}

/**
 * @return list<string>
 */
function syncRegistryKeys(): array
{
    /** @var PermissionRegistry $registry */
    $registry = app(PermissionRegistry::class);

    return $registry->keys();
}

/**
 * Every `permissions` row, keyed by key, as plain arrays - so two snapshots
 * can be compared by value.
 *
 * @return array<string, array<string, mixed>>
 */
function syncStoredPermissions(): array
{
    $rows = [];

    foreach (syncTenant()->table('permissions')->orderBy('key')->get() as $row) {
        $values = (array) $row;
        /** @var string $key */
        $key = $values['key'];
        $rows[$key] = $values;
    }

    return $rows;
}

function syncDeclaration(string $key, string $description = 'A fixture declaration.', bool $scoped = false): PermissionDeclaration
{
    return new PermissionDeclaration($key, 'fixture', 'fixture', $description, $scoped);
}

/**
 * One employee, so "no perm_version moved" has something to be true of.
 */
function syncEmployee(string $stem = 'sync-one'): string
{
    $id = str_pad(strtoupper($stem), 26, '0');

    syncTenant()->table('employees')->insert([
        'id' => $id,
        'code' => $stem,
        'first_name' => 'Test',
        'display_name' => 'Test',
        'status' => 'active',
        'perm_version' => 1,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);

    return $id;
}

/*
|--------------------------------------------------------------------------
| AC-15 - the first sync, and AC-18's sweep
|--------------------------------------------------------------------------
*/

it('inserts every declared key into an empty table and reports the four counts', function () {
    expect(syncTenant()->table('permissions')->count())->toBe(0);

    $result = runSync();

    // The sweep, enumerated from the registry rather than from a list here,
    // with its domain printed: a hand-written list could not see a key
    // nobody listed, which is the mutation the last case in this file makes.
    $declared = syncRegistryKeys();

    echo 'declared keys ('.count($declared).'): '.implode(', ', $declared)."\n";

    expect($declared)->not->toBeEmpty()
        ->and(array_keys(syncStoredPermissions()))->toBe($declared)
        ->and($result->inserted)->toBe($declared)
        ->and($result->counts())->toBe([
            'inserted' => count($declared),
            'updated' => 0,
            'unchanged' => 0,
            'orphaned' => 0,
            'refused' => 0,
        ])
        // AC-14's six are in there, by name, because "the table equals the
        // registry" is also true of two empty sets.
        ->and($declared)->toContain(AccessPermissions::EMPLOYEES_VIEW)
        ->and($declared)->toContain(AccessPermissions::ACCESS_ASSIGN);
});

it('stores each declaration exactly as the provider declared it', function () {
    runSync();

    $stored = syncStoredPermissions();

    foreach (AccessPermissions::declarations() as $declaration) {
        expect($stored[$declaration->key]['module'])->toBe($declaration->module)
            ->and($stored[$declaration->key]['group'])->toBe($declaration->group)
            ->and($stored[$declaration->key]['description'])->toBe($declaration->description)
            ->and((bool) $stored[$declaration->key]['is_branch_scoped'])->toBe($declaration->isBranchScoped);
    }
});

/*
|--------------------------------------------------------------------------
| AC-15 - the second sync changes nothing at all
|--------------------------------------------------------------------------
*/

it('changes no row and no perm_version when it runs again', function () {
    $employee = syncEmployee();

    runSync();

    $before = syncStoredPermissions();

    $result = runSync();

    expect($result->counts()['inserted'])->toBe(0)
        ->and($result->counts()['updated'])->toBe(0)
        ->and($result->unchanged)->toBe(syncRegistryKeys())
        ->and($result->changedNothing())->toBeTrue()
        // The rows themselves, by value. "inserted 0" is also what a sync
        // that rewrote every row with the same values reports.
        ->and(syncStoredPermissions())->toBe($before)
        ->and(syncTenant()->table('employees')->where('id', $employee)->value('perm_version'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| AC-15 - a changed attribute is an update
|--------------------------------------------------------------------------
*/

it('updates a stored key whose attributes no longer match the declaration', function () {
    runSync();

    // The state a deployment is in after the code's description changed and
    // the sync has not run yet.
    syncTenant()->table('permissions')
        ->where('key', AccessPermissions::ROLES_VIEW)
        ->update(['description' => 'What an older release said.']);

    $result = runSync();

    $declared = collect(AccessPermissions::declarations())
        ->firstOrFail(static fn (PermissionDeclaration $d): bool => $d->key === AccessPermissions::ROLES_VIEW);

    expect($result->updated)->toBe([AccessPermissions::ROLES_VIEW])
        ->and($result->counts()['updated'])->toBe(1)
        ->and(syncStoredPermissions()[AccessPermissions::ROLES_VIEW]['description'])->toBe($declared->description)
        // Only that one: an update that rewrote the set would also report 1.
        ->and($result->counts()['unchanged'])->toBe(count(syncRegistryKeys()) - 1);
});

/*
|--------------------------------------------------------------------------
| AC-15 - the scope flip is refused for that key, and the rest proceeds
|--------------------------------------------------------------------------
*/

it('refuses to flip is_branch_scoped on a stored key and syncs everything else', function () {
    // A key stored branch-scoped, declared company-wide. Flipping it would
    // silently change what every grant of it means (D-8 (c)).
    syncTenant()->table('permissions')->insert([
        'key' => 'fixture.flipped',
        'module' => 'fixture',
        'group' => 'fixture',
        'description' => 'Stored as branch-scoped.',
        'is_branch_scoped' => true,
    ]);

    FixturePermissionProvider::declaring(
        app(),
        syncDeclaration('fixture.flipped', 'Declared as company-wide.'),
        // The second key is what makes "the rest proceeds" assertable: a
        // guard written as an early return would stop here.
        syncDeclaration('fixture.alongside'),
    );

    $result = runSync();

    $stored = syncStoredPermissions();

    expect($result->refused)->toBe(['fixture.flipped'])
        // Untouched, both halves of it: the flag the sync refused to move
        // and the description it would have moved in the same statement.
        ->and((bool) $stored['fixture.flipped']['is_branch_scoped'])->toBeTrue()
        ->and($stored['fixture.flipped']['description'])->toBe('Stored as branch-scoped.')
        ->and($result->inserted)->toContain('fixture.alongside')
        ->and(array_keys($stored))->toContain(AccessPermissions::EMPLOYEES_MANAGE);
});

/*
|--------------------------------------------------------------------------
| AC-16 - an orphan is reported, never deleted, and its grants survive
|--------------------------------------------------------------------------
*/

it('reports a stored key nothing declares and leaves it and its grants alone', function () {
    $role = str_pad('ROLE-ORPHAN', 26, '0');

    syncTenant()->table('permissions')->insert([
        'key' => 'ghost.capability',
        'module' => 'ghost',
        'group' => 'ghost',
        'description' => 'Declared by a module that is not booted here.',
        'is_branch_scoped' => false,
    ]);

    syncTenant()->table('roles')->insert([
        'id' => $role,
        'key' => 'ghost-holder',
        'name' => 'Ghost holder',
        'is_system' => false,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);

    syncTenant()->table('role_permissions')->insert([
        'role_id' => $role,
        'permission_key' => 'ghost.capability',
    ]);

    $result = runSync();

    expect($result->orphaned)->toBe(['ghost.capability'])
        ->and($result->counts()['orphaned'])->toBe(1)
        // Not deleted - which the foreign key alone would also have
        // prevented, so the grant is asserted separately: deleting the grant
        // and then the key would have satisfied the key assertion.
        ->and(array_keys(syncStoredPermissions()))->toContain('ghost.capability')
        ->and(syncTenant()->table('role_permissions')
            ->where('role_id', $role)
            ->where('permission_key', 'ghost.capability')
            ->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| AC-14 - a set that cannot be reconciled writes nothing at all
|--------------------------------------------------------------------------
*/

it('writes nothing when one key is declared two different ways', function () {
    FixturePermissionProvider::declaring(app(), syncDeclaration('fixture.disputed', 'One reading.'));
    SecondFixturePermissionProvider::declaring(app(), syncDeclaration('fixture.disputed', 'Another reading.'));

    $refusal = null;

    try {
        runSync();
    } catch (AccessRefused $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->toBeInstanceOf(AccessRefused::class)
        ->and($refusal?->reason)->toBe(RefusalReason::Validation)
        // Nothing at all, not even the six that were fine: the whole sync
        // fails (AC-14), and it fails before a transaction is opened.
        ->and(syncTenant()->table('permissions')->count())->toBe(0);
});

it('writes the same set once the conflicting declaration is gone', function () {
    // The liveness half of the case above. An empty table is also what a
    // sync that never reached the database leaves behind.
    FixturePermissionProvider::declaring(app(), syncDeclaration('fixture.disputed', 'One reading.'));
    SecondFixturePermissionProvider::declaring(app(), syncDeclaration('fixture.disputed', 'One reading.'));

    $result = runSync();

    expect($result->inserted)->toContain('fixture.disputed')
        ->and(syncTenant()->table('permissions')->count())->toBe(count(syncRegistryKeys()));
});

/*
|--------------------------------------------------------------------------
| AC-18 - the key nobody listed
|--------------------------------------------------------------------------
*/

it('syncs a key declared by a provider no list in this file mentions', function () {
    FixturePermissionProvider::declaring(app(), syncDeclaration('unlisted.capability'));

    runSync();

    // The mandatory mutation for an enumerating instrument (D-27, shape 7).
    // The sweep above compares the table with the registry, so a key that
    // appears in neither list in this file still has to be found; a sweep
    // written against a literal six would pass with this key missing from
    // the table.
    expect(array_keys(syncStoredPermissions()))->toBe(syncRegistryKeys())
        ->and(syncRegistryKeys())->toContain('unlisted.capability');
});
