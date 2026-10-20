<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermission;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermissionHandler;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissions;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissionsHandler;
use Flexipos\Contracts\Access\RefusalReason;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 14 - nobody hands out what they do not hold (AC-36)
|--------------------------------------------------------------------------
|
| The criterion names the control and it is the shape every case here
| follows: the actor lacks EXACTLY ONE of the role's keys and is refused;
| that one key is then granted and the IDENTICAL request succeeds. An
| actor holding nothing would be refused by a guard that checked anything
| at all, including a broken one - lacking exactly one key is what makes
| the refusal attributable.
|
| The three clauses are tested apart because they ask three different
| questions: a role assignment checks every key of the role, a per-person
| allow checks one key at that scope, and `roles.manage` adding a key
| checks that key company-wide. A per-person DENY checks nothing, and that
| is asserted positively - an actor who holds nothing can still deny.
|
| The null-scope case is the one `decide()` cannot answer and the reason
| `holdsEverywhere()` exists: the key is branch-scoped, the grant is for
| every branch, and an actor holding it at one branch only must be
| refused. An implementation that asked `decide($actor, $key, null)` would
| get `branch_required` and refuse everybody, including the owner - that
| case is here too, as the thing that would catch it.
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

function escalationAssign(string $employeeId, string $roleId, ?string $branchId, ?string $actorId): void
{
    /** @var AssignRoleHandler $handler */
    $handler = app(AssignRoleHandler::class);

    $handler->handle(new AssignRole($employeeId, $roleId, $branchId, $actorId));
}

function escalationSetPermission(
    string $employeeId,
    string $key,
    bool $allowed,
    ?string $branchId,
    ?string $actorId
): void {
    /** @var SetEmployeePermissionHandler $handler */
    $handler = app(SetEmployeePermissionHandler::class);

    $handler->handle(new SetEmployeePermission($employeeId, $key, $allowed, $branchId, $actorId));
}

/**
 * @param  list<string>  $keys
 */
function escalationSetRolePermissions(string $roleId, array $keys, ?string $actorId): void
{
    /** @var SetRolePermissionsHandler $handler */
    $handler = app(SetRolePermissionsHandler::class);

    $handler->handle(new SetRolePermissions($roleId, $keys, $actorId));
}

function escalationHolds(string $employeeId, string $key, ?string $branchId = null): void
{
    AccessSeed::personal($employeeId, $key, true, $branchId);
}

/**
 * Two company-wide keys, a role holding both, a subject and a branch.
 *
 * @return array<string, string>
 */
function escalationWorld(): array
{
    $ids = [
        'first' => AccessSeed::permission('fixture.first'),
        'second' => AccessSeed::permission('fixture.second'),
        'scoped' => AccessSeed::permission('fixture.scoped', true),
        'branch' => AccessSeed::branch(),
    ];

    $ids['role'] = AccessSeed::role('shift_lead', [$ids['first'], $ids['second']]);
    $ids['subject'] = AccessSeed::employee('subject');
    $ids['actor'] = AccessSeed::employee('actor');

    AccessSeed::attach($ids['subject'], $ids['branch']);

    return $ids;
}

it('refuses an actor lacking exactly one of the roles keys, and accepts once that key is granted (AC-36)', function () {
    $ids = escalationWorld();

    escalationHolds($ids['actor'], $ids['first']);

    AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => escalationAssign($ids['subject'], $ids['role'], null, $ids['actor'])
    );

    expect(AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['subject'])->count())->toBe(0);

    // The one missing key, and nothing else about the request changes.
    escalationHolds($ids['actor'], $ids['second']);

    escalationAssign($ids['subject'], $ids['role'], null, $ids['actor']);

    expect(AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['subject'])->count())->toBe(1);
});

it('names the key the actor is missing, so the refusal can be acted on (AC-36)', function () {
    $ids = escalationWorld();

    escalationHolds($ids['actor'], $ids['first']);

    $refused = AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => escalationAssign($ids['subject'], $ids['role'], null, $ids['actor'])
    );

    expect($refused->getMessage())->toContain($ids['second'])
        ->and($refused->getMessage())->not->toContain($ids['first']);
});

it('refuses a grant with no actor at all, because an unbounded grant is bounded by nobody (AC-36)', function () {
    $ids = escalationWorld();

    AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => escalationAssign($ids['subject'], $ids['role'], null, null)
    );

    expect(AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['subject'])->count())->toBe(0);
});

it('refuses a per-person allow of a key the actor does not hold, and accepts it once they do (AC-36)', function () {
    $ids = escalationWorld();

    AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => escalationSetPermission($ids['subject'], $ids['first'], true, null, $ids['actor'])
    );

    escalationHolds($ids['actor'], $ids['first']);

    escalationSetPermission($ids['subject'], $ids['first'], true, null, $ids['actor']);

    expect(AccessSeed::connection()->table('employee_permissions')
        ->where('employee_id', $ids['subject'])->count())->toBe(1);
});

it('lets an actor who holds nothing write a per-person deny (AC-36)', function () {
    $ids = escalationWorld();

    escalationSetPermission($ids['subject'], $ids['first'], false, null, $ids['actor']);

    $row = AccessSeed::connection()->table('employee_permissions')
        ->where('employee_id', $ids['subject'])
        ->first();

    expect($row)->not->toBeNull()
        ->and((bool) $row->allowed)->toBeFalse();
});

it('refuses a grant at a branch the actor does not hold the key at, and accepts at one they do (AC-36)', function () {
    $ids = escalationWorld();

    $elsewhere = AccessSeed::branch();
    AccessSeed::attach($ids['subject'], $elsewhere);

    $branchRole = AccessSeed::role('branch_lead', [$ids['scoped']]);

    // The actor holds the branch-scoped key at ONE branch only.
    escalationHolds($ids['actor'], $ids['scoped'], $ids['branch']);

    AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => escalationAssign($ids['subject'], $branchRole, $elsewhere, $ids['actor'])
    );

    escalationAssign($ids['subject'], $branchRole, $ids['branch'], $ids['actor']);

    expect(AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['subject'])->count())->toBe(1);
});

it('refuses an all-branches grant by an actor who holds the key at one branch only (AC-36)', function () {
    $ids = escalationWorld();

    $branchRole = AccessSeed::role('branch_lead', [$ids['scoped']]);

    escalationHolds($ids['actor'], $ids['scoped'], $ids['branch']);

    AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => escalationAssign($ids['subject'], $branchRole, null, $ids['actor'])
    );

    // The control, and the case that would catch an implementation asking
    // `decide($actor, $key, null)`: a company-wide holding IS enough, and
    // `decide()` would answer `branch_required` for it and refuse.
    escalationHolds($ids['actor'], $ids['scoped']);

    escalationAssign($ids['subject'], $branchRole, null, $ids['actor']);

    expect(AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['subject'])->count())->toBe(1);
});

it('refuses an actor adding a key to a role that they do not hold company-wide (AC-36)', function () {
    $ids = escalationWorld();

    $custom = AccessSeed::role('tidy', [$ids['first']]);

    escalationHolds($ids['actor'], $ids['first']);

    AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => escalationSetRolePermissions($custom, [$ids['first'], $ids['second']], $ids['actor'])
    );

    escalationHolds($ids['actor'], $ids['second']);

    escalationSetRolePermissions($custom, [$ids['first'], $ids['second']], $ids['actor']);

    expect(AccessSeed::connection()->table('role_permissions')
        ->where('role_id', $custom)->count())->toBe(2);
});

it('lets an actor remove a key from a role without holding it (AC-36)', function () {
    $ids = escalationWorld();

    $custom = AccessSeed::role('tidy', [$ids['first'], $ids['second']]);

    // The actor holds neither key, and takes one away. Nobody gains
    // anything, so there is nothing to escalate - and requiring the key
    // here would make a role unmaintainable by the person asked to tidy it.
    escalationSetRolePermissions($custom, [$ids['first']], $ids['actor']);

    expect(AccessSeed::connection()->table('role_permissions')
        ->where('role_id', $custom)->count())->toBe(1);
});

it('does not consult the actor for a role that confers nothing (AC-36)', function () {
    $ids = escalationWorld();

    $empty = AccessSeed::role('observer', []);

    escalationAssign($ids['subject'], $empty, null, $ids['actor']);

    expect(AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['subject'])->count())->toBe(1);
});
