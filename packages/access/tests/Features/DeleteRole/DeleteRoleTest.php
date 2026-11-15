<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\DeleteRole;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\DeleteRole\DeleteRole;
use Flexipos\Access\Features\DeleteRole\DeleteRoleHandler;
use Flexipos\Access\Features\ListRoles\ListRoles;
use Flexipos\Access\Features\ListRoles\ListRolesHandler;
use Flexipos\Access\Support\RoleView;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 11 - deleting a role (AC-21, AC-22)
|--------------------------------------------------------------------------
|
| `role_in_use` is asserted for an assignment at a SPECIFIC branch as well
| as at a null scope. AC-22 says "assigned to at least one employee at any
| scope", and a guard written as `where('branch_id', null)` would pass a
| file that only ever assigned company-wide.
|
| Soft deletion is the only deletion: the row is still there afterwards, and
| that is asserted positively rather than inferred from the list no longer
| showing it. A hard delete would also empty the list.
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

function deleteRole(string $roleId): void
{
    /** @var DeleteRoleHandler $handler */
    $handler = app(DeleteRoleHandler::class);

    $handler->handle(new DeleteRole($roleId));
}

/**
 * @return list<string>
 */
function listedRoleKeys(): array
{
    /** @var ListRolesHandler $handler */
    $handler = app(ListRolesHandler::class);

    return array_map(static fn (RoleView $view): string => $view->key, $handler->handle(new ListRoles));
}

it('soft-deletes an unassigned role and takes it out of the list (AC-22)', function () {
    $roleId = AccessSeed::role('shift_lead');

    deleteRole($roleId);

    $row = AccessSeed::connection()->table('roles')->where('id', $roleId)->first();

    expect($row)->not->toBeNull()
        ->and($row->deleted_at)->not->toBeNull()
        ->and(listedRoleKeys())->not->toContain('shift_lead');
});

it('refuses a role assigned at a null scope or at a branch, and changes nothing (AC-22)', function () {
    foreach ([null, AccessSeed::branch()] as $index => $branchId) {
        $roleId = AccessSeed::role('in_use_'.$index);
        $employeeId = AccessSeed::employee('holder-'.$index);
        AccessSeed::assign($employeeId, $roleId, $branchId);

        try {
            deleteRole($roleId);

            expect(false)->toBeTrue('a role in use must not be deletable');
        } catch (AccessRefused $refused) {
            expect($refused->reason)->toBe(RefusalReason::RoleInUse);
        }

        expect(AccessSeed::connection()->table('roles')->where('id', $roleId)->value('deleted_at'))->toBeNull()
            ->and(AccessSeed::connection()->table('employee_roles')->where('role_id', $roleId)->count())->toBe(1);
    }
});

it('deletes the same role once its only assignment is gone, which is the control (AC-22)', function () {
    $roleId = AccessSeed::role('shift_lead');
    $employeeId = AccessSeed::employee('holder');
    AccessSeed::assign($employeeId, $roleId);

    expect(fn () => deleteRole($roleId))->toThrow(AccessRefused::class);

    AccessSeed::connection()->table('employee_roles')->where('role_id', $roleId)->delete();

    deleteRole($roleId);

    expect(AccessSeed::connection()->table('roles')->where('id', $roleId)->value('deleted_at'))->not->toBeNull();
});

it('refuses to delete a system role, and that refusal is not role_in_use (AC-21)', function () {
    $roleId = AccessSeed::role(SystemRole::OWNER_KEY, [], ['is_system' => true]);

    try {
        deleteRole($roleId);

        expect(false)->toBeTrue('a system role must not be deletable');
    } catch (AccessRefused $refused) {
        expect($refused->reason)->toBe(RefusalReason::SystemRole);
    }

    expect(AccessSeed::connection()->table('roles')->where('id', $roleId)->value('deleted_at'))->toBeNull();
});

it('refuses a role that is already deleted, or that never existed, with validation (AC-22)', function () {
    $roleId = AccessSeed::role('gone', [], ['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    try {
        deleteRole($roleId);

        expect(false)->toBeTrue('a deleted role must not be deletable twice');
    } catch (AccessRefused $refused) {
        expect($refused->reason)->toBe(RefusalReason::Validation);
    }
});
