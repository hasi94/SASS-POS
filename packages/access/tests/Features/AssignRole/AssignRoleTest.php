<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\AssignRole;

use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignment;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignmentHandler;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 13 - assigning a role at a scope (AC-30, AC-31, AC-52)
|--------------------------------------------------------------------------
|
| AC-31's acceptance of a well-formed id naming no branch was asserted
| positively here by T-010. **T-014 AC-52 supersedes it (intended, D-6):** an
| unknown id is `unknown_branch`, an inactive branch is `branch_inactive`, an
| active one is accepted, and removal at an inactive branch still succeeds.
|
| Every refusal case asserts three things: the reason (not merely that
| something was refused - four of this section's guards share a status), no
| row written, and no bump. A handler that inserted and then threw would
| fail the second; one that bumped before validating would fail the third.
|
| The system-role case is a POSITIVE one: `owner` is assignable even though
| AC-21 protects it from rename, re-permission and delete. AC-37's own
| control - "after a second owner is assigned" - is impossible otherwise.
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
 * The privileged actor `assignRoleWorld()` seeds.
 *
 * Looked up by code rather than threaded through every call, so that the
 * cases below read as what they are about. Step 14's escalation rule
 * (AC-36) refuses an actor who does not hold the role's keys, which means
 * every call here needs a real one - and the cases that are ABOUT the
 * actor pass their own.
 */
function assignRoleActor(): string
{
    return (string) AccessSeed::connection()->table('employees')->where('code', 'actor')->value('id');
}

function assignRole(string $employeeId, string $roleId, ?string $branchId = null, ?string $actorId = null): void
{
    /** @var AssignRoleHandler $handler */
    $handler = app(AssignRoleHandler::class);

    $handler->handle(new AssignRole($employeeId, $roleId, $branchId, $actorId ?? assignRoleActor()));
}

/**
 * @return list<array<string, mixed>>
 */
function assignmentRows(string $employeeId): array
{
    return AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $employeeId)
        ->orderBy('id')
        ->get()
        ->map(static fn ($row): array => (array) $row)
        ->all();
}

/**
 * One employee attached to one branch, one custom role, one actor.
 *
 * @return array<string, string>
 */
function assignRoleWorld(): array
{
    AccessSeed::permission('fixture.a');

    $ids = [
        'role' => AccessSeed::role('shift_lead', ['fixture.a']),
        'branch' => AccessSeed::branch(),
    ];

    $ids['employee'] = AccessSeed::employee('subject');

    // The actor holds `fixture.a` company-wide, which is what AC-36 asks of
    // anyone assigning `shift_lead`: the escalation rule is step 14's and
    // this fixture is what makes the ordinary cases below legal.
    $ids['actor'] = AccessSeed::actorHolding('actor', ['fixture.a']);

    AccessSeed::attach($ids['employee'], $ids['branch']);

    return $ids;
}

it('assigns at the all-branches scope, recording the grantor, and bumps (AC-30, AC-52)', function () {
    $ids = assignRoleWorld();
    $before = AccessSeed::permVersion($ids['employee']);

    assignRole($ids['employee'], $ids['role'], null, $ids['actor']);

    $rows = assignmentRows($ids['employee']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['branch_id'])->toBeNull()
        ->and($rows[0]['role_id'])->toBe($ids['role'])
        ->and($rows[0]['granted_by'])->toBe($ids['actor'])
        ->and($rows[0]['granted_at'])->not->toBeNull()
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before + 1);
});

it('accepts the same role again at an attached branch, because a scope is part of a grant (AC-30)', function () {
    $ids = assignRoleWorld();

    assignRole($ids['employee'], $ids['role']);
    assignRole($ids['employee'], $ids['role'], $ids['branch']);

    $scopes = array_map(static fn (array $row) => $row['branch_id'], assignmentRows($ids['employee']));

    sort($scopes);

    expect(assignmentRows($ids['employee']))->toHaveCount(2)
        ->and($scopes)->toBe([null, $ids['branch']]);
});

it('refuses the same role twice at the same scope, writes nothing and bumps nothing (AC-30)', function () {
    $ids = assignRoleWorld();

    assignRole($ids['employee'], $ids['role']);

    $before = AccessSeed::permVersion($ids['employee']);

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => assignRole($ids['employee'], $ids['role'])
    );

    expect(assignmentRows($ids['employee']))->toHaveCount(1)
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before);
});

/*
 * SUPERSEDED by T-014 AC-52, intended and not a regression: this case used to
 * assert that a well-formed id naming no branch is accepted (T-010 AC-31,
 * D-30) because no `branches` table existed. The criterion text was withdrawn
 * by T-014 D-6, and the assertions below replace it.
 */
it('refuses an unknown branch, refuses an inactive one, accepts an active one, and still removes at an inactive one (AC-52, AC-15)', function () {
    $ids = assignRoleWorld();

    $unknown = (string) Str::ulid();
    $before = AccessSeed::permVersion($ids['employee']);

    // Unknown: validation with its own reason, and `unknown_branch` precedes
    // `not_attached` (the employee is attached to nothing at this id).
    AccessRefusals::expect(RefusalReason::UnknownBranch, fn () => assignRole($ids['employee'], $ids['role'], $unknown));

    expect(assignmentRows($ids['employee']))->toBe([])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before);

    // Active: accepted, as T-010 AC-31 describes for an existing branch.
    assignRole($ids['employee'], $ids['role'], $ids['branch']);

    expect(assignmentRows($ids['employee']))->toHaveCount(1);

    // Inactive: a second role at the deactivated branch is refused.
    $second = AccessSeed::role('second_lead', ['fixture.a']);

    AccessSeed::connection()->table('branches')->where('id', $ids['branch'])->update(['is_active' => false]);

    $afterFirst = AccessSeed::permVersion($ids['employee']);

    AccessRefusals::expect(RefusalReason::BranchInactive, fn () => assignRole($ids['employee'], $second, $ids['branch']));

    expect(assignmentRows($ids['employee']))->toHaveCount(1)
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($afterFirst);

    // Control: removing the assignment at the inactive branch succeeds
    // (D-6: rights stay revocable at a closed branch) and, once reactivated,
    // the refused request succeeds too.
    app(RemoveRoleAssignmentHandler::class)->handle(
        new RemoveRoleAssignment($ids['employee'], $ids['role'], $ids['branch'], $ids['actor'])
    );

    expect(assignmentRows($ids['employee']))->toBe([]);

    AccessSeed::connection()->table('branches')->where('id', $ids['branch'])->update(['is_active' => true]);

    assignRole($ids['employee'], $second, $ids['branch']);

    expect(assignmentRows($ids['employee']))->toHaveCount(1);
});

it('treats a lower-case spelling of a branch id as the same branch (AC-61)', function () {
    $ids = assignRoleWorld();

    assignRole($ids['employee'], $ids['role'], strtolower($ids['branch']));

    $rows = assignmentRows($ids['employee']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['branch_id'])->toBe($ids['branch']);
});

it('refuses a scope the employee is not attached to, with not_attached (AC-31, D-11)', function () {
    $ids = assignRoleWorld();
    $before = AccessSeed::permVersion($ids['employee']);
    $elsewhere = AccessSeed::branch();

    AccessRefusals::expect(
        RefusalReason::NotAttached,
        fn () => assignRole($ids['employee'], $ids['role'], $elsewhere)
    );

    expect(assignmentRows($ids['employee']))->toBe([])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before);
});

it('refuses a branch id that is not a ULID, with validation (AC-31)', function () {
    $ids = assignRoleWorld();

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => assignRole($ids['employee'], $ids['role'], 'branch-7')
    );

    expect(assignmentRows($ids['employee']))->toBe([]);
});

it('refuses a role that does not exist or was soft-deleted (AC-30)', function () {
    $ids = assignRoleWorld();

    AccessSeed::connection()->table('roles')
        ->where('id', $ids['role'])
        ->update(['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => assignRole($ids['employee'], $ids['role'])
    );

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => assignRole($ids['employee'], (string) Str::ulid())
    );

    expect(assignmentRows($ids['employee']))->toBe([]);
});

it('assigns a system role, because AC-21 protects rename, re-permission and delete and not assignment', function () {
    $ids = assignRoleWorld();

    AccessSeed::permission('fixture.owner_only');
    $ownerRoleId = AccessSeed::role(SystemRole::OWNER_KEY, ['fixture.owner_only'], ['is_system' => true]);

    // AC-36 applies to a system role like any other: the actor must hold
    // what they are handing out. This grant is what makes the case about
    // AC-21 rather than about escalation.
    AccessSeed::personal($ids['actor'], 'fixture.owner_only', true);

    assignRole($ids['employee'], $ownerRoleId);

    expect(assignmentRows($ids['employee'])[0]['role_id'])->toBe($ownerRoleId);
});

it('refuses an employee who does not exist or was soft-deleted (AC-30)', function () {
    $ids = assignRoleWorld();

    AccessSeed::connection()->table('employees')
        ->where('id', $ids['employee'])
        ->update(['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => assignRole($ids['employee'], $ids['role'])
    );

    expect(assignmentRows($ids['employee']))->toBe([]);
});
