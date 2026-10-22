<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\SyncPermissionRegistry;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryResult;
use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Access\Tests\Support\FixturePermissionProvider;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 7 - the owner role
|--------------------------------------------------------------------------
|
| AC-17. The sync seeds a system role `owner` holding every registered key
| BY DATA - never by a bypass in code (D-8 (f)) - and a newly declared key
| joins it and bumps every employee holding it, in the same transaction. A
| second sync bumps nothing.
|
| **The bump's negative control is a pair and the pair is what makes it
| attributable.** An employee holding owner is bumped; an employee holding
| only a custom role, and an employee holding no role at all, are not. With
| only the first half, a sync that bumped every employee in the shop - or
| every employee whenever it ran - would pass.
|
| **"In the same transaction" is observed as the property, not as a
| consequence.** A probe records the connection's transaction level while
| each statement runs, so the grant and the bump are seen INSIDE a
| transaction rather than inferred from both having happened; and the probe
| has its own liveness control, because a probe that is reading nothing
| reports the same zero as a statement that really ran outside one.
|
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();

    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * This file's own handle on the sync.
 *
 * Declared here rather than reused from the sibling test file: a filtered
 * single-file run of this one would never load that file, which is the
 * defect FP-164 moved the tenancy fixture out of a test file to fix.
 */
function ownerSync(): SyncPermissionRegistryResult
{
    /** @var SyncPermissionRegistryHandler $handler */
    $handler = app(SyncPermissionRegistryHandler::class);

    return $handler->handle(new SyncPermissionRegistry);
}

function ownerRoleId(): string
{
    return (string) ownerTenant()->table('roles')->where('key', SystemRole::OWNER_KEY)->value('id');
}

function ownerTenant(): Connection
{
    /** @var Connection $connection */
    $connection = DB::connection(AccessTenantFixture::connectionName());

    return $connection;
}

/**
 * @return list<string>
 */
function ownerHeldKeys(): array
{
    $keys = ownerTenant()->table('role_permissions')
        ->where('role_id', ownerRoleId())
        ->pluck('permission_key')
        ->map(static fn ($key): string => (string) $key)
        ->all();

    sort($keys);

    return $keys;
}

/**
 * @return list<string>
 */
function ownerDeclaredKeys(): array
{
    /** @var PermissionRegistry $registry */
    $registry = app(PermissionRegistry::class);

    return $registry->keys();
}

function ownerEmployee(string $stem): string
{
    $id = str_pad(strtoupper($stem), 26, '0');

    ownerTenant()->table('employees')->insert([
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

/**
 * Make sure a branch row holds this id (T-014 step 6).
 *
 * The contract migration keys every branch column onto `branches`, so a row
 * naming an opaque id needs the branch it names. Seeding only: no assertion
 * in this file changes.
 */
function ownerEnsureBranch(?string $branchId): void
{
    if ($branchId === null || ownerTenant()->table('branches')->where('id', $branchId)->exists()) {
        return;
    }

    ownerTenant()->table('branches')->insert([
        'id' => $branchId,
        'code' => 'B'.substr(md5($branchId), 0, 8),
        'name' => 'Seeded '.$branchId,
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);
}

function ownerAssign(string $employeeId, string $roleId, ?string $branchId = null): void
{
    ownerEnsureBranch($branchId);

    ownerTenant()->table('employee_roles')->insert([
        'id' => str_pad(strtoupper(substr($employeeId, 0, 10).substr($roleId, 0, 6)), 26, 'X'),
        'employee_id' => $employeeId,
        'role_id' => $roleId,
        'branch_id' => $branchId,
        'granted_at' => '2026-10-20 00:00:00.000',
    ]);
}

function ownerCustomRole(): string
{
    $id = str_pad('ROLE-CUSTOM', 26, '0');

    ownerTenant()->table('roles')->insert([
        'id' => $id,
        'key' => 'custom',
        'name' => 'Custom',
        'is_system' => false,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);

    return $id;
}

function ownerVersion(string $employeeId): int
{
    return (int) ownerTenant()->table('employees')->where('id', $employeeId)->value('perm_version');
}

/*
|--------------------------------------------------------------------------
| AC-17 - the role is seeded, and it holds everything by data
|--------------------------------------------------------------------------
*/

it('seeds a system owner role holding every registered key', function () {
    $result = ownerSync();

    $owner = ownerTenant()->table('roles')->where('key', SystemRole::OWNER_KEY)->first();

    expect($owner)->not->toBeNull()
        ->and((bool) $owner?->is_system)->toBeTrue()
        ->and($result->ownerCreated)->toBeTrue()
        // By data: the grants are rows, so a reviewer can see what the owner
        // may do, and a later resolver needs no special case for it.
        ->and(ownerHeldKeys())->toBe(ownerDeclaredKeys())
        ->and($result->ownerGranted)->toBe(ownerDeclaredKeys())
        ->and(ownerHeldKeys())->not->toBeEmpty();
});

it('creates nothing, grants nothing and bumps nothing on a second sync', function () {
    ownerSync();

    $holder = ownerEmployee('owner-holder');
    ownerAssign($holder, ownerRoleId());

    $result = ownerSync();

    expect($result->ownerCreated)->toBeFalse()
        ->and($result->ownerGranted)->toBe([])
        ->and($result->bumped)->toBe(0)
        ->and($result->changedNothing())->toBeTrue()
        ->and(ownerVersion($holder))->toBe(1)
        // One owner role, not a second one beside it.
        ->and(ownerTenant()->table('roles')->where('key', SystemRole::OWNER_KEY)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| AC-17 - a new key joins owner and bumps its holders, and only its holders
|--------------------------------------------------------------------------
*/

it('adds a newly declared key to owner and bumps every holder of it', function () {
    ownerSync();

    $ownerRole = ownerRoleId();
    $custom = ownerCustomRole();

    $atLarge = ownerEmployee('holder-null');
    $atBranch = ownerEmployee('holder-branch');
    $customOnly = ownerEmployee('holder-custom');
    $roleless = ownerEmployee('holder-none');

    ownerAssign($atLarge, $ownerRole);
    // At a branch, because AC-17 says "at any scope": a bump that only
    // found null-scope holders would pass without this one.
    ownerAssign($atBranch, $ownerRole, str_pad('BRANCH-ONE', 26, '0'));
    ownerAssign($customOnly, $custom);

    $result = ownerSync();

    expect($result->ownerGranted)->toBe([])
        ->and($result->bumped)->toBe(0);

    // The key nobody listed, arriving from a provider registered at runtime.
    FixturePermissionProvider::declaring(
        app(),
        new PermissionDeclaration('unlisted.capability', 'fixture', 'fixture', 'A late arrival.', false),
    );

    $result = ownerSync();

    expect($result->ownerGranted)->toBe(['unlisted.capability'])
        ->and(ownerHeldKeys())->toContain('unlisted.capability')
        ->and($result->bumped)->toBe(2)
        // The holders, at both scopes.
        ->and(ownerVersion($atLarge))->toBe(2)
        ->and(ownerVersion($atBranch))->toBe(2)
        // The negative half, and it is what makes the bump attributable to
        // holding owner rather than to the sync having run at all.
        ->and(ownerVersion($customOnly))->toBe(1)
        ->and(ownerVersion($roleless))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| AC-17 - the grant and the bump are inside one transaction
|--------------------------------------------------------------------------
*/

it('grants and bumps inside a transaction, and the probe can tell', function () {
    ownerSync();

    $holder = ownerEmployee('txn-holder');
    ownerAssign($holder, ownerRoleId());

    $connection = ownerTenant();

    /** @var list<array{sql: string, level: int}> $seen */
    $seen = [];

    $connection->listen(function (QueryExecuted $query) use (&$seen, $connection): void {
        // Read DURING the statement, not after it: the level afterwards is
        // the level of whatever the test is doing, which is the shape that
        // survives the very wrapper the criterion is about.
        $seen[] = ['sql' => $query->sql, 'level' => $connection->transactionLevel()];
    });

    FixturePermissionProvider::declaring(
        app(),
        new PermissionDeclaration('transactional.capability', 'fixture', 'fixture', 'A late arrival.', false),
    );

    ownerSync();

    $grants = array_values(array_filter(
        $seen,
        static fn (array $row): bool => str_contains($row['sql'], 'insert into "role_permissions"')
    ));

    $bumps = array_values(array_filter(
        $seen,
        static fn (array $row): bool => str_contains($row['sql'], 'update "employees"')
    ));

    $outside = array_values(array_filter(
        array_merge($grants, $bumps),
        static fn (array $row): bool => $row['level'] === 0
    ));

    expect($grants)->not->toBeEmpty()
        ->and($bumps)->not->toBeEmpty()
        ->and($outside)->toBe([])
        ->and(ownerVersion($holder))->toBe(2);

    // The probe's liveness control. A probe reading nothing - a listener
    // that never fired, a level that is always zero because it is being
    // read in the wrong place - reports exactly what a clean run reports.
    // This statement really is outside a transaction, and the probe must
    // say so.
    $seen = [];

    $connection->table('employees')->where('id', $holder)->update(['first_name' => 'Probe']);

    $levels = array_map(static fn (array $row): int => $row['level'], $seen);

    expect($seen)->not->toBeEmpty()
        ->and($levels)->toBe([0]);
});

it('grants nothing to a role keyed owner that is not a system role, and reports it (AC-17, review S-3)', function () {
    ownerTenant()->table('roles')->insert([
        'id' => str_pad('ROLE-IMPOSTOR', 26, '0'),
        'key' => SystemRole::OWNER_KEY,
        'name' => 'Owner',
        'is_system' => false,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);

    $result = ownerSync();

    // The flag decides, as everywhere else: the custom role holds nothing,
    // nothing was seeded beside it, and the result says why.
    expect($result->ownerNotSystem)->toBeTrue()
        ->and($result->ownerCreated)->toBeFalse()
        ->and($result->ownerGranted)->toBe([])
        ->and($result->bumped)->toBe(0)
        ->and(ownerHeldKeys())->toBe([])
        ->and(ownerTenant()->table('roles')->where('key', SystemRole::OWNER_KEY)->count())->toBe(1);

    // The control: the same row made a system role is topped up in full,
    // and the report clears.
    ownerTenant()->table('roles')->where('key', SystemRole::OWNER_KEY)->update(['is_system' => true]);

    $repaired = ownerSync();

    expect($repaired->ownerNotSystem)->toBeFalse()
        ->and($repaired->ownerGranted)->toBe(ownerDeclaredKeys())
        ->and(ownerHeldKeys())->toBe(ownerDeclaredKeys());
});
