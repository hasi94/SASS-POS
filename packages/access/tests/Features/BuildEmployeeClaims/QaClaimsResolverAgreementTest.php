<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\BuildEmployeeClaims;

use Flexipos\Access\Features\BuildEmployeeClaims\BuildEmployeeClaims;
use Flexipos\Access\Features\BuildEmployeeClaims\BuildEmployeeClaimsHandler;
use Flexipos\Access\Features\GetMyPermissions\GetMyPermissions;
use Flexipos\Access\Features\GetMyPermissions\GetMyPermissionsHandler;
use Flexipos\Access\Support\PermHash;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\PermissionResolver;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 QA - the claims and the resolver must agree about a deleted role
|--------------------------------------------------------------------------
|
| AC-43: "Roles that are soft-deleted contribute nothing". The resolver
| honours it (`roles.deleted_at is null` in its role join). The CLAIMS read
| their contributions through a different query - `PermHash::contributionsFor`
| joins `employee_roles` to `role_permissions` and never looks at `roles` -
| and feed `all_branches`, `perm_hash` and the "my permissions" list from it.
|
| So for an assignment whose role has been soft-deleted (AC-22 stops that
| happening through the operations, so it takes a fixture, a repair or a
| race to produce) the resolver says "not permitted" while the token says
| `all_branches: true` and the hash covers a grant that grants nothing. Two
| opinions about what a grant covers is the shape this project has filed
| three times (FP-184); the plan's Ruling 10 asked for exactly this kind of
| agreement control between the resolver's two paths and did not ask for it
| between the resolver and the claims.
|
| The fixture is the same one AC-43 names for the resolver ("asserted by
| direct fixture"), and the paired control is a LIVE role in the same shape,
| which must contribute to all three.
|
*/

uses(TestCase::class);

const QA_AGREE_USER = '01JBUSER000000000000000AGR';

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
 * @return array{allBranches: bool, hash: string, contributions: int, resolverAllows: bool}
 */
function qaAgreeFor(string $employeeId, string $key): array
{
    $claims = app(BuildEmployeeClaimsHandler::class)->handle(new BuildEmployeeClaims($employeeId, QA_AGREE_USER));
    $mine = app(GetMyPermissionsHandler::class)->handle(new GetMyPermissions($employeeId));

    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    return [
        'allBranches' => $claims->allBranches,
        'hash' => $claims->permHash,
        'contributions' => count($mine->contributions),
        'resolverAllows' => $resolver->decide($employeeId, $key, null)->allowed,
    ];
}

it('lets a live role contribute to the resolver, the claims and my permissions alike (control)', function () {
    $key = AccessSeed::permission('fixture.company');
    $employee = AccessSeed::employee('holder', ['user_id' => QA_AGREE_USER]);

    AccessSeed::assign($employee, AccessSeed::role('live_role', [$key]));

    $seen = qaAgreeFor($employee, $key);

    expect($seen['resolverAllows'])->toBeTrue()
        ->and($seen['allBranches'])->toBeTrue()
        ->and($seen['contributions'])->toBe(1);
});

it('lets a soft-deleted role contribute to none of the three (AC-43, AC-57, AC-54)', function () {
    $key = AccessSeed::permission('fixture.company');
    $employee = AccessSeed::employee('holder', ['user_id' => QA_AGREE_USER]);
    $bare = AccessSeed::employee('bare', ['user_id' => '01JBUSER000000000000000BAR']);

    AccessSeed::assign($employee, AccessSeed::role('gone_role', [$key], [
        'deleted_at' => now()->format('Y-m-d H:i:s.v'),
    ]));

    $seen = qaAgreeFor($employee, $key);

    // The resolver's opinion (AC-43), which the claims must share.
    expect($seen['resolverAllows'])->toBeFalse()
        ->and($seen['allBranches'])->toBeFalse('the token says all_branches for a role that grants nothing')
        ->and($seen['contributions'])->toBe(0, 'my permissions lists a grant of a deleted role');

    // And the hash is the hash of an employee with no contributions at the
    // same version: a deleted role must not be fingerprinted as a grant.
    $version = AccessSeed::permVersion($employee);

    expect($seen['hash'])->toBe(PermHash::compute($version, []));

    unset($bare);
});
