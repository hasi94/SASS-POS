<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatus;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatusHandler;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployee;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployeeHandler;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignment;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignmentHandler;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Contracts\Access\RefusalReason;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 14 - a tenant never loses its last acting owner (AC-37)
|--------------------------------------------------------------------------
|
| Three operations can produce the outcome and all three are here, each
| with the control the criterion names: a second owner. The pairing is
| what makes the refusal attributable - `last_owner` shares a status with
| `self_change` and `no_escalation`, and the removal case can be refused
| by two of the three guards at once if the fixture is careless, so the
| actor in every case is a DIFFERENT employee who holds what they need.
|
| Three negative cases bound the guard from the other side, and each of
| them is a way a careless implementation blocks something legitimate:
| removing a NON-owner role from the last owner, moving the owner between
| two acting statuses, and a second owner whose assignment is scoped to a
| branch (which does not administer the shop and must not count).
|
| What is NOT here, deliberately: AC-37's concurrency half. Two removals
| racing, each seeing the other as the remaining owner, is [ENGINE] and
| ships NOT VERIFIED. The guard is a read followed by a write and only a
| real engine can say whether both can win; nothing in this file is
| evidence either way, and a green run here must not be reported as one.
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

function removeOwnerRole(string $employeeId, string $roleId, ?string $actorId, ?string $branchId = null): void
{
    /** @var RemoveRoleAssignmentHandler $handler */
    $handler = app(RemoveRoleAssignmentHandler::class);

    $handler->handle(new RemoveRoleAssignment($employeeId, $roleId, $branchId, $actorId));
}

function changeOwnerStatus(string $employeeId, string $status, ?string $actorId): void
{
    /** @var ChangeEmployeeStatusHandler $handler */
    $handler = app(ChangeEmployeeStatusHandler::class);

    $handler->handle(new ChangeEmployeeStatus($employeeId, $status, $actorId));
}

function deleteOwner(string $employeeId, ?string $actorId): void
{
    /** @var DeleteEmployeeHandler $handler */
    $handler = app(DeleteEmployeeHandler::class);

    $handler->handle(new DeleteEmployee($employeeId, $actorId));
}

/**
 * One acting owner, an administrator who is not one, and a custom role.
 *
 * @return array<string, string>
 */
function lastOwnerWorld(): array
{
    $ids = [
        'key' => AccessSeed::permission('fixture.key'),
    ];

    $ids['ownerRole'] = AccessSeed::role(
        SystemRole::OWNER_KEY,
        [$ids['key']],
        ['is_system' => true]
    );
    $ids['customRole'] = AccessSeed::role('shift_lead', [$ids['key']]);

    $ids['owner'] = AccessSeed::employee('the-owner');
    AccessSeed::assign($ids['owner'], $ids['ownerRole']);

    // The actor is somebody else, so a refusal cannot be `self_change`,
    // and holds the key so it cannot be `no_escalation`.
    $ids['actor'] = AccessSeed::actorHolding('actor', [$ids['key']]);

    return $ids;
}

/**
 * Give the tenant a second acting owner.
 *
 * @param  array<string, string>  $ids
 */
function secondOwner(array $ids): string
{
    $id = AccessSeed::employee('second-owner');

    AccessSeed::assign($id, $ids['ownerRole']);

    return $id;
}

it('refuses to remove the only owner assignment, and allows it once a second owner exists (AC-37)', function () {
    $ids = lastOwnerWorld();

    AccessRefusals::expect(
        RefusalReason::LastOwner,
        fn () => removeOwnerRole($ids['owner'], $ids['ownerRole'], $ids['actor'])
    );

    expect(AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['owner'])->count())->toBe(1);

    secondOwner($ids);

    removeOwnerRole($ids['owner'], $ids['ownerRole'], $ids['actor']);

    expect(AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['owner'])->count())->toBe(0);
});

it('refuses to move the only owner out of the acting set, and allows it once a second owner exists (AC-37)', function () {
    $ids = lastOwnerWorld();

    AccessRefusals::expect(
        RefusalReason::LastOwner,
        fn () => changeOwnerStatus($ids['owner'], EmployeeStatus::ON_LEAVE, $ids['actor'])
    );

    expect(AccessSeed::employeeRow($ids['owner'])['status'])->toBe(EmployeeStatus::ACTIVE);

    secondOwner($ids);

    changeOwnerStatus($ids['owner'], EmployeeStatus::ON_LEAVE, $ids['actor']);

    expect(AccessSeed::employeeRow($ids['owner'])['status'])->toBe(EmployeeStatus::ON_LEAVE);
});

it('refuses to soft-delete the only owner, and allows it once a second owner exists (AC-37)', function () {
    $ids = lastOwnerWorld();

    AccessRefusals::expect(
        RefusalReason::LastOwner,
        fn () => deleteOwner($ids['owner'], $ids['actor'])
    );

    expect(AccessSeed::employeeRow($ids['owner'])['deleted_at'])->toBeNull();

    secondOwner($ids);

    deleteOwner($ids['owner'], $ids['actor']);

    expect(AccessSeed::employeeRow($ids['owner'])['deleted_at'])->not->toBeNull();
});

it('allows a non-owner role to be removed from the only owner (AC-37)', function () {
    $ids = lastOwnerWorld();

    AccessSeed::assign($ids['owner'], $ids['customRole']);

    removeOwnerRole($ids['owner'], $ids['customRole'], $ids['actor']);

    expect(AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['owner'])
        ->where('role_id', $ids['ownerRole'])
        ->count())->toBe(1);
});

it('allows the only owner to move between two acting statuses (AC-37, AC-27)', function () {
    $ids = lastOwnerWorld();

    changeOwnerStatus($ids['owner'], EmployeeStatus::PROBATION, $ids['actor']);

    expect(AccessSeed::employeeRow($ids['owner'])['status'])->toBe(EmployeeStatus::PROBATION);
});

it('does not count an owner assignment scoped to a branch as an owner (AC-37)', function () {
    $ids = lastOwnerWorld();

    $branchId = AccessSeed::branch();
    $branchOwner = AccessSeed::employee('branch-owner');

    AccessSeed::attach($branchOwner, $branchId);
    AccessSeed::assign($branchOwner, $ids['ownerRole'], $branchId);

    // A role held at one branch does not administer the shop, so the real
    // owner is still the last one and the refusal must stand.
    AccessRefusals::expect(
        RefusalReason::LastOwner,
        fn () => deleteOwner($ids['owner'], $ids['actor'])
    );
});

it('does not count a non-acting employee as an owner (AC-37)', function () {
    $ids = lastOwnerWorld();

    $suspended = AccessSeed::employee('suspended-owner', ['status' => EmployeeStatus::SUSPENDED]);
    AccessSeed::assign($suspended, $ids['ownerRole']);

    AccessRefusals::expect(
        RefusalReason::LastOwner,
        fn () => deleteOwner($ids['owner'], $ids['actor'])
    );

    // The control: the same employee made acting again is a second owner,
    // and the identical delete succeeds.
    AccessSeed::connection()->table('employees')
        ->where('id', $suspended)
        ->update(['status' => EmployeeStatus::ACTIVE]);

    deleteOwner($ids['owner'], $ids['actor']);

    expect(AccessSeed::employeeRow($ids['owner'])['deleted_at'])->not->toBeNull();
});

it('reads the is_system flag and not the key owner (AC-37, AC-21)', function () {
    $ids = lastOwnerWorld();

    // A shop naming its own custom role `owner` must not thereby become a
    // second owner: the flag is what counts.
    $impostor = AccessSeed::employee('impostor');
    $impostorRole = AccessSeed::role('owner_ish', [$ids['key']], ['is_system' => false]);
    AccessSeed::assign($impostor, $impostorRole);

    AccessRefusals::expect(
        RefusalReason::LastOwner,
        fn () => deleteOwner($ids['owner'], $ids['actor'])
    );

    // And the inverse: a system role that is not called `owner` DOES count,
    // so renaming a platform role cannot disable the guard.
    $renamed = AccessSeed::employee('renamed-owner');
    $renamedRole = AccessSeed::role('proprietor', [$ids['key']], ['is_system' => true]);
    AccessSeed::assign($renamed, $renamedRole);

    deleteOwner($ids['owner'], $ids['actor']);

    expect(AccessSeed::employeeRow($ids['owner'])['deleted_at'])->not->toBeNull();
});
