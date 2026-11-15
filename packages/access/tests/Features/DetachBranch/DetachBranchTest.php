<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\DetachBranch;

use Flexipos\Access\Features\DetachBranch\DetachBranch;
use Flexipos\Access\Features\DetachBranch\DetachBranchHandler;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 13 - detaching, and the grants that forbid it (AC-35, D-11)
|--------------------------------------------------------------------------
|
| `has_scoped_grants` is proved once per grant table and the list of tables
| is READ FROM the production constant rather than written here, so a third
| grant table added later without a check is a red test rather than a
| silent hole. The domain is printed by the last case in this file
| (D-27: an instrument that enumerates publishes its domain) and asserted
| non-empty.
|
| Each refusal is paired with the operation that lifts it: remove the
| scoped grant and the identical detach succeeds. Without the pair, a
| handler that refused every detach would pass the refusal cases.
|
| A grant at the ALL-BRANCHES scope must not block a detach, and that case
| is here because it is the one a `where('branch_id', ...)` written without
| thought gets wrong in the safe-looking direction: it would block forever.
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

function detachBranch(string $employeeId, string $branchId): void
{
    /** @var DetachBranchHandler $handler */
    $handler = app(DetachBranchHandler::class);

    $handler->handle(new DetachBranch($employeeId, $branchId));
}

function isAttached(string $employeeId, string $branchId): bool
{
    return AccessSeed::connection()->table('employee_branches')
        ->where('employee_id', $employeeId)
        ->where('branch_id', $branchId)
        ->exists();
}

/**
 * An employee attached to one branch, which is their default.
 *
 * @return array<string, string>
 */
function detachBranchWorld(): array
{
    $ids = [
        'key' => AccessSeed::permission('fixture.scoped', true),
        'branch' => AccessSeed::branch(),
    ];

    $ids['role'] = AccessSeed::role('shift_lead', [$ids['key']]);
    $ids['employee'] = AccessSeed::employee('subject');

    AccessSeed::attach($ids['employee'], $ids['branch'], true);

    return $ids;
}

it('detaches a branch nothing is scoped to, and bumps (AC-35, AC-52)', function () {
    $ids = detachBranchWorld();
    $before = AccessSeed::permVersion($ids['employee']);

    detachBranch($ids['employee'], $ids['branch']);

    expect(isAttached($ids['employee'], $ids['branch']))->toBeFalse()
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before + 1);
});

it('leaves the employee with no default when the default is detached (AC-35)', function () {
    $ids = detachBranchWorld();

    detachBranch($ids['employee'], $ids['branch']);

    $defaults = AccessSeed::connection()->table('employee_branches')
        ->where('employee_id', $ids['employee'])
        ->where('is_default', true)
        ->count();

    expect($defaults)->toBe(0);
});

it('refuses while a role is assigned at that branch, and succeeds once it is gone (AC-35, D-11)', function () {
    $ids = detachBranchWorld();

    AccessSeed::assign($ids['employee'], $ids['role'], $ids['branch']);

    $before = AccessSeed::permVersion($ids['employee']);

    AccessRefusals::expect(
        RefusalReason::HasScopedGrants,
        fn () => detachBranch($ids['employee'], $ids['branch'])
    );

    expect(isAttached($ids['employee'], $ids['branch']))->toBeTrue()
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before);

    AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['employee'])
        ->where('branch_id', $ids['branch'])
        ->delete();

    detachBranch($ids['employee'], $ids['branch']);

    expect(isAttached($ids['employee'], $ids['branch']))->toBeFalse();
});

it('refuses while a per-person row is scoped to that branch, and succeeds once it is gone (AC-35, D-11)', function () {
    $ids = detachBranchWorld();

    AccessSeed::personal($ids['employee'], $ids['key'], true, $ids['branch']);

    AccessRefusals::expect(
        RefusalReason::HasScopedGrants,
        fn () => detachBranch($ids['employee'], $ids['branch'])
    );

    expect(isAttached($ids['employee'], $ids['branch']))->toBeTrue();

    AccessSeed::connection()->table('employee_permissions')
        ->where('employee_id', $ids['employee'])
        ->where('branch_id', $ids['branch'])
        ->delete();

    detachBranch($ids['employee'], $ids['branch']);

    expect(isAttached($ids['employee'], $ids['branch']))->toBeFalse();
});

it('is not blocked by grants at the all-branches scope, which name no branch (AC-35)', function () {
    $ids = detachBranchWorld();

    AccessSeed::assign($ids['employee'], $ids['role']);
    AccessSeed::personal($ids['employee'], $ids['key'], true);

    detachBranch($ids['employee'], $ids['branch']);

    expect(isAttached($ids['employee'], $ids['branch']))->toBeFalse();
});

it('is not blocked by another employee holding a grant at the same branch (AC-35)', function () {
    $ids = detachBranchWorld();

    $other = AccessSeed::employee('other');
    AccessSeed::attach($other, $ids['branch']);
    AccessSeed::assign($other, $ids['role'], $ids['branch']);

    detachBranch($ids['employee'], $ids['branch']);

    expect(isAttached($ids['employee'], $ids['branch']))->toBeFalse()
        ->and(isAttached($other, $ids['branch']))->toBeTrue();
});

it('refuses a branch the employee is not attached to, with validation (AC-35)', function () {
    $ids = detachBranchWorld();

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => detachBranch($ids['employee'], AccessSeed::branch())
    );

    expect(isAttached($ids['employee'], $ids['branch']))->toBeTrue();
});

it('publishes the grant tables it examines, and every one of them blocks (D-27)', function () {
    $ids = detachBranchWorld();

    // One seeder per grant table, keyed by the table it writes. The keys are
    // compared against the production constant rather than against a list
    // written here twice: a third grant table added to `BranchScope` with no
    // seeder in this file turns the comparison red, which is the whole point
    // of publishing the domain instead of describing it.
    $seeders = [
        'employee_roles' => static function (string $employeeId, string $branchId) use ($ids): void {
            AccessSeed::assign($employeeId, $ids['role'], $branchId);
        },
        'employee_permissions' => static function (string $employeeId, string $branchId) use ($ids): void {
            AccessSeed::personal($employeeId, $ids['key'], true, $branchId);
        },
    ];

    expect(BranchScope::GRANT_TABLES)->not->toBe([])
        ->and(array_keys($seeders))->toBe(BranchScope::GRANT_TABLES);

    $index = 0;

    foreach ($seeders as $seed) {
        $employeeId = AccessSeed::employee('blocked-'.$index++);
        $branchId = AccessSeed::branch();

        AccessSeed::attach($employeeId, $branchId);
        $seed($employeeId, $branchId);

        AccessRefusals::expect(
            RefusalReason::HasScopedGrants,
            fn () => detachBranch($employeeId, $branchId)
        );

        expect(isAttached($employeeId, $branchId))->toBeTrue();
    }
});
