<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\RemoveRoleAssignment;

use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignment;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignmentHandler;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 13 - removing one scoped assignment (AC-33, AC-52)
|--------------------------------------------------------------------------
|
| The fixture gives the employee the SAME role at two scopes, which is what
| makes the scoping assertion discriminating: a handler that dropped the
| scope from its `where` would delete both rows and still look like a
| success. The criterion is "the row is gone", and here "the other row is
| still there" is the half that detects the mistake worth detecting.
|
| AC-33's "bumps nothing" is asserted rather than inferred, in both
| directions: the absent-row refusal leaves `perm_version` exactly where it
| was, and the successful removal raises it by one in the same test file so
| that a handler which never bumped at all could not pass by doing nothing.
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

function removeRoleAssignment(string $employeeId, string $roleId, ?string $branchId = null): void
{
    /** @var RemoveRoleAssignmentHandler $handler */
    $handler = app(RemoveRoleAssignmentHandler::class);

    $handler->handle(new RemoveRoleAssignment($employeeId, $roleId, $branchId));
}

/**
 * @return list<?string>
 */
function remainingScopes(string $employeeId, string $roleId): array
{
    $scopes = AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $employeeId)
        ->where('role_id', $roleId)
        ->pluck('branch_id')
        ->map(static fn ($scope): ?string => $scope === null ? null : (string) $scope)
        ->all();

    sort($scopes);

    return $scopes;
}

/**
 * One employee holding one role at two scopes.
 *
 * @return array<string, string>
 */
function removeAssignmentWorld(): array
{
    AccessSeed::permission('fixture.a');

    $ids = [
        'role' => AccessSeed::role('shift_lead', ['fixture.a']),
        'branch' => AccessSeed::branch(),
    ];

    $ids['employee'] = AccessSeed::employee('subject');

    AccessSeed::attach($ids['employee'], $ids['branch']);
    AccessSeed::assign($ids['employee'], $ids['role']);
    AccessSeed::assign($ids['employee'], $ids['role'], $ids['branch']);

    return $ids;
}

it('removes the all-branches grant and leaves the branch grant standing (AC-33, AC-52)', function () {
    $ids = removeAssignmentWorld();
    $before = AccessSeed::permVersion($ids['employee']);

    removeRoleAssignment($ids['employee'], $ids['role']);

    expect(remainingScopes($ids['employee'], $ids['role']))->toBe([$ids['branch']])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before + 1);
});

it('removes the branch grant and leaves the all-branches grant standing (AC-33)', function () {
    $ids = removeAssignmentWorld();

    removeRoleAssignment($ids['employee'], $ids['role'], $ids['branch']);

    expect(remainingScopes($ids['employee'], $ids['role']))->toBe([null]);
});

it('refuses to remove a grant that is not there and bumps nothing (AC-33)', function () {
    $ids = removeAssignmentWorld();

    removeRoleAssignment($ids['employee'], $ids['role']);

    $before = AccessSeed::permVersion($ids['employee']);

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => removeRoleAssignment($ids['employee'], $ids['role'])
    );

    expect(remainingScopes($ids['employee'], $ids['role']))->toBe([$ids['branch']])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before);
});

it('refuses a scope the employee holds nothing at, even though the role is held elsewhere (AC-33)', function () {
    $ids = removeAssignmentWorld();
    $elsewhere = AccessSeed::branch();

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => removeRoleAssignment($ids['employee'], $ids['role'], $elsewhere)
    );

    expect(remainingScopes($ids['employee'], $ids['role']))->toBe([null, $ids['branch']]);
});

it('refuses a branch id that is not a ULID before it touches a row (AC-31)', function () {
    $ids = removeAssignmentWorld();

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => removeRoleAssignment($ids['employee'], $ids['role'], 'branch-7')
    );

    expect(remainingScopes($ids['employee'], $ids['role']))->toBe([null, $ids['branch']]);
});
