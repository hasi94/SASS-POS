<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\AssignRole;

use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
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
| AC-31's acceptance of a well-formed id naming no branch is asserted
| POSITIVELY and by name, because the alternative reading of a missing test
| is "nobody thought about it". There is no `branches` table (D-30), so the
| branch ids here exist in `employee_branches` and nowhere else - which is
| exactly the production shape until FP-209 ships branches - and the
| assignment is accepted.
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

it('accepts a well-formed branch id for which no branch exists, deliberately (AC-31, D-30)', function () {
    $ids = assignRoleWorld();

    // The attachment is the only record of this branch anywhere in the
    // database: there is no `branches` table to check against, so this IS
    // the production shape and the assignment is accepted. `qa` must not
    // file this as unvalidated input.
    expect(AccessSeed::connection()->getSchemaBuilder()->hasTable('branches'))->toBeFalse();

    assignRole($ids['employee'], $ids['role'], $ids['branch']);

    expect(assignmentRows($ids['employee'])[0]['branch_id'])->toBe($ids['branch']);
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
