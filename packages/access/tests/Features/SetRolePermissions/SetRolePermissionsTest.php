<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\SetRolePermissions;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissions;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissionsHandler;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 11 - setting a role's permissions (AC-20, AC-21, AC-52)
|--------------------------------------------------------------------------
|
| Replace, not merge, and the fixture is built so the two readings differ:
| the role starts holding A and B, and is set to B and C. A merge leaves A
| in place, which is a row this file asserts is gone - a fixture whose new
| set was a superset of the old one could not tell the two apart.
|
| The bump is asserted on EVERY holder at EVERY scope, with a non-holder in
| the same tenant whose version must not move. Without the non-holder, a
| handler that bumped the whole table would pass.
|
| The unregistered-key case asserts three things, because "refused" is only
| the first: the reason is `validation`, no row of `role_permissions`
| changed, and no holder was bumped. A handler that wrote the valid keys and
| then threw would fail the second; one that bumped before validating would
| fail the third.
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
 * @param  list<string>  $keys
 */
function setRolePermissions(string $roleId, array $keys): int
{
    /** @var SetRolePermissionsHandler $handler */
    $handler = app(SetRolePermissionsHandler::class);

    return $handler->handle(new SetRolePermissions($roleId, $keys))->bumped;
}

/**
 * Three keys, a role holding the first two, two holders at two scopes and
 * one employee holding nothing.
 *
 * @return array<string, string>
 */
function rolePermissionWorld(): array
{
    AccessSeed::permission('fixture.a');
    AccessSeed::permission('fixture.b');
    AccessSeed::permission('fixture.c');

    $ids = [
        'role' => AccessSeed::role('shift_lead', ['fixture.a', 'fixture.b']),
        'branch' => AccessSeed::branch(),
    ];

    $ids['holderNull'] = AccessSeed::employee('holder-null');
    AccessSeed::assign($ids['holderNull'], $ids['role']);

    $ids['holderBranch'] = AccessSeed::employee('holder-branch');
    AccessSeed::assign($ids['holderBranch'], $ids['role'], $ids['branch']);

    $ids['stranger'] = AccessSeed::employee('stranger');

    return $ids;
}

/**
 * @return list<string>
 */
function storedRoleKeys(string $roleId): array
{
    $keys = AccessSeed::connection()->table('role_permissions')
        ->where('role_id', $roleId)
        ->pluck('permission_key')
        ->map(static fn ($key): string => (string) $key)
        ->all();

    sort($keys);

    return $keys;
}

it('replaces the set rather than merging it, and bumps every holder at every scope (AC-20, AC-52)', function () {
    $ids = rolePermissionWorld();

    $before = [
        'holderNull' => AccessSeed::permVersion($ids['holderNull']),
        'holderBranch' => AccessSeed::permVersion($ids['holderBranch']),
        'stranger' => AccessSeed::permVersion($ids['stranger']),
    ];

    $bumped = setRolePermissions($ids['role'], ['fixture.b', 'fixture.c']);

    expect(storedRoleKeys($ids['role']))->toBe(['fixture.b', 'fixture.c'])
        ->and($bumped)->toBe(2)
        ->and(AccessSeed::permVersion($ids['holderNull']))->toBe($before['holderNull'] + 1)
        ->and(AccessSeed::permVersion($ids['holderBranch']))->toBe($before['holderBranch'] + 1)
        ->and(AccessSeed::permVersion($ids['stranger']))->toBe($before['stranger']);
});

it('accepts an empty list and removes every permission (AC-20)', function () {
    $ids = rolePermissionWorld();

    $bumped = setRolePermissions($ids['role'], []);

    expect(storedRoleKeys($ids['role']))->toBe([])
        ->and($bumped)->toBe(2);
});

it('refuses a list containing an unregistered key, changes no row and bumps nobody (AC-20)', function () {
    $ids = rolePermissionWorld();

    $before = AccessSeed::permVersion($ids['holderNull']);

    try {
        setRolePermissions($ids['role'], ['fixture.c', 'fixture.never_declared']);

        expect(false)->toBeTrue('an unregistered key must be refused');
    } catch (AccessRefused $refused) {
        expect($refused->reason)->toBe(RefusalReason::Validation);
    }

    expect(storedRoleKeys($ids['role']))->toBe(['fixture.a', 'fixture.b'])
        ->and(AccessSeed::permVersion($ids['holderNull']))->toBe($before);
});

it('refuses to re-permission a system role and leaves its grants intact (AC-21)', function () {
    AccessSeed::permission('fixture.a');
    AccessSeed::permission('fixture.b');

    $roleId = AccessSeed::role(SystemRole::OWNER_KEY, ['fixture.a'], ['is_system' => true]);

    try {
        setRolePermissions($roleId, ['fixture.b']);

        expect(false)->toBeTrue('a system role must not be re-permissioned');
    } catch (AccessRefused $refused) {
        expect($refused->reason)->toBe(RefusalReason::SystemRole);
    }

    expect(storedRoleKeys($roleId))->toBe(['fixture.a']);
});
