<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\ClearEmployeePermission;

use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermission;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermissionHandler;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 13 - clearing a per-person row (AC-32, AC-33, AC-52)
|--------------------------------------------------------------------------
|
| The fixture seeds the same key at two scopes and clears one, so "the
| other scope survived" is asserted rather than assumed - the same
| discrimination `RemoveRoleAssignmentTest` needs and for the same reason.
|
| Clearing a deny and clearing an allow are both exercised. They are
| opposite in effect (one restores a role's grant, one withdraws a personal
| one) and identical in mechanism, and a test that only ever cleared an
| allow could not tell a handler that filtered on `allowed = 1` apart from
| a correct one.
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

function clearEmployeePermission(string $employeeId, string $key, ?string $branchId = null): void
{
    /** @var ClearEmployeePermissionHandler $handler */
    $handler = app(ClearEmployeePermissionHandler::class);

    $handler->handle(new ClearEmployeePermission($employeeId, $key, $branchId));
}

/**
 * @return list<?string>
 */
function remainingPersonalScopes(string $employeeId, string $key): array
{
    $scopes = AccessSeed::connection()->table('employee_permissions')
        ->where('employee_id', $employeeId)
        ->where('permission_key', $key)
        ->pluck('branch_id')
        ->map(static fn ($scope): ?string => $scope === null ? null : (string) $scope)
        ->all();

    sort($scopes);

    return $scopes;
}

/**
 * A deny at the all-branches scope and an allow at a branch, same key.
 *
 * @return array<string, string>
 */
function clearPermissionWorld(): array
{
    $ids = [
        'key' => AccessSeed::permission('fixture.scoped', true),
        'branch' => AccessSeed::branch(),
    ];

    $ids['employee'] = AccessSeed::employee('subject');

    AccessSeed::attach($ids['employee'], $ids['branch']);
    AccessSeed::personal($ids['employee'], $ids['key'], false);
    AccessSeed::personal($ids['employee'], $ids['key'], true, $ids['branch']);

    return $ids;
}

it('clears the deny at the all-branches scope and leaves the branch allow (AC-33, AC-52)', function () {
    $ids = clearPermissionWorld();
    $before = AccessSeed::permVersion($ids['employee']);

    clearEmployeePermission($ids['employee'], $ids['key']);

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([$ids['branch']])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before + 1);
});

it('clears the allow at the branch and leaves the all-branches deny (AC-33)', function () {
    $ids = clearPermissionWorld();

    clearEmployeePermission($ids['employee'], $ids['key'], $ids['branch']);

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([null]);
});

it('refuses to clear a row that is not there and bumps nothing (AC-33)', function () {
    $ids = clearPermissionWorld();

    clearEmployeePermission($ids['employee'], $ids['key']);

    $before = AccessSeed::permVersion($ids['employee']);

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => clearEmployeePermission($ids['employee'], $ids['key'])
    );

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([$ids['branch']])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before);
});

it('refuses an unregistered key the same way an absent row is refused (AC-33)', function () {
    $ids = clearPermissionWorld();

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => clearEmployeePermission($ids['employee'], 'fixture.never_declared')
    );

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([null, $ids['branch']]);
});
