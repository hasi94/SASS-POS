<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\SetEmployeePermission;

use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermission;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermissionHandler;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 13 - a per-person allow or deny (AC-32, AC-52)
|--------------------------------------------------------------------------
|
| Three refusals share this slice and they are three different causes, so
| every case asserts WHICH: `validation` for an unregistered key,
| `scope_not_applicable` for a company-wide key named with a branch (D-8
| (e)), `not_attached` for a branch the employee is not attached to (D-11).
| A test asserting only "it was refused" would pass against a handler that
| met every one of them with the same guard, and the remedies differ.
|
| The fixture declares one branch-scoped key and one that is not, because
| the `scope_not_applicable` case is invisible without the pair: with only
| a non-scoped key, a handler refusing EVERY branch would also pass.
|
| "Replaces, not accumulates" is asserted by flipping an allow to a deny at
| the same scope and counting the rows - the null-safe unique key would
| refuse a second row anyway, so a handler that only inserted would fail
| with an integrity error rather than silently; the count is what tells the
| two apart in the report.
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
 * The privileged actor `personalPermissionWorld()` seeds.
 *
 * AC-36 refuses a per-person **allow** by an actor who does not hold the
 * key, so from step 14 onward every allow below needs one; the cases that
 * are about the actor pass their own.
 */
function personalPermissionActor(): string
{
    return (string) AccessSeed::connection()->table('employees')->where('code', 'actor')->value('id');
}

function setEmployeePermission(
    string $employeeId,
    string $key,
    bool $allowed,
    ?string $branchId = null,
    ?string $actorId = null
): void {
    /** @var SetEmployeePermissionHandler $handler */
    $handler = app(SetEmployeePermissionHandler::class);

    $handler->handle(new SetEmployeePermission(
        $employeeId,
        $key,
        $allowed,
        $branchId,
        $actorId ?? personalPermissionActor()
    ));
}

/**
 * @return list<array<string, mixed>>
 */
function personalRows(string $employeeId): array
{
    return AccessSeed::connection()->table('employee_permissions')
        ->where('employee_id', $employeeId)
        ->orderBy('permission_key')
        ->get()
        ->map(static fn ($row): array => (array) $row)
        ->all();
}

/**
 * One scoped key, one company-wide key, one attached branch.
 *
 * @return array<string, string>
 */
function personalPermissionWorld(): array
{
    $ids = [
        'scoped' => AccessSeed::permission('fixture.scoped', true),
        'wide' => AccessSeed::permission('fixture.wide'),
        'branch' => AccessSeed::branch(),
    ];

    $ids['employee'] = AccessSeed::employee('subject');
    $ids['actor'] = AccessSeed::actorHolding('actor', [$ids['scoped'], $ids['wide']]);

    AccessSeed::attach($ids['employee'], $ids['branch']);

    return $ids;
}

it('stores an allow at the all-branches scope and bumps (AC-32, AC-52)', function () {
    $ids = personalPermissionWorld();
    $before = AccessSeed::permVersion($ids['employee']);

    setEmployeePermission($ids['employee'], $ids['wide'], true);

    $rows = personalRows($ids['employee']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['permission_key'])->toBe($ids['wide'])
        ->and($rows[0]['branch_id'])->toBeNull()
        ->and((bool) $rows[0]['allowed'])->toBeTrue()
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before + 1);
});

it('stores a deny at an attached branch for a branch-scoped key (AC-32)', function () {
    $ids = personalPermissionWorld();

    setEmployeePermission($ids['employee'], $ids['scoped'], false, $ids['branch']);

    $rows = personalRows($ids['employee']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['branch_id'])->toBe($ids['branch'])
        ->and((bool) $rows[0]['allowed'])->toBeFalse();
});

it('replaces the row at the same scope rather than adding a second (AC-32)', function () {
    $ids = personalPermissionWorld();

    setEmployeePermission($ids['employee'], $ids['wide'], true);
    $afterFirst = AccessSeed::permVersion($ids['employee']);

    setEmployeePermission($ids['employee'], $ids['wide'], false);

    $rows = personalRows($ids['employee']);

    expect($rows)->toHaveCount(1)
        ->and((bool) $rows[0]['allowed'])->toBeFalse()
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($afterFirst + 1);
});

it('keeps the branch row and the all-branches row apart (AC-32)', function () {
    $ids = personalPermissionWorld();

    setEmployeePermission($ids['employee'], $ids['scoped'], true);
    setEmployeePermission($ids['employee'], $ids['scoped'], false, $ids['branch']);

    expect(personalRows($ids['employee']))->toHaveCount(2);
});

it('refuses an unregistered key with validation and writes nothing (AC-32)', function () {
    $ids = personalPermissionWorld();
    $before = AccessSeed::permVersion($ids['employee']);

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => setEmployeePermission($ids['employee'], 'fixture.never_declared', true)
    );

    expect(personalRows($ids['employee']))->toBe([])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before);
});

it('refuses a branch for a key that is not branch-scoped, with scope_not_applicable (AC-32, D-8)', function () {
    $ids = personalPermissionWorld();
    $before = AccessSeed::permVersion($ids['employee']);

    AccessRefusals::expect(
        RefusalReason::ScopeNotApplicable,
        fn () => setEmployeePermission($ids['employee'], $ids['wide'], true, $ids['branch'])
    );

    expect(personalRows($ids['employee']))->toBe([])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before);

    // The control that makes the refusal mean "this key", not "any branch":
    // the branch-scoped key at the identical branch is accepted.
    setEmployeePermission($ids['employee'], $ids['scoped'], true, $ids['branch']);

    expect(personalRows($ids['employee']))->toHaveCount(1);
});

it('refuses a branch the employee is not attached to, with not_attached (AC-32, D-11)', function () {
    $ids = personalPermissionWorld();
    $elsewhere = AccessSeed::branch();

    AccessRefusals::expect(
        RefusalReason::NotAttached,
        fn () => setEmployeePermission($ids['employee'], $ids['scoped'], true, $elsewhere)
    );

    expect(personalRows($ids['employee']))->toBe([]);

    // Control: attaching that branch makes the identical request succeed.
    AccessSeed::attach($ids['employee'], $elsewhere);

    setEmployeePermission($ids['employee'], $ids['scoped'], true, $elsewhere);

    expect(personalRows($ids['employee']))->toHaveCount(1);
});

it('refuses a branch id that is not a ULID, with validation (AC-31)', function () {
    $ids = personalPermissionWorld();

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => setEmployeePermission($ids['employee'], $ids['scoped'], true, 'branch-7')
    );

    expect(personalRows($ids['employee']))->toBe([]);
});

it('refuses an allow and a deny at an unknown or inactive branch (AC-52, AC-15)', function () {
    $ids = personalPermissionWorld();

    AccessRefusals::expect(RefusalReason::UnknownBranch, fn () => setEmployeePermission($ids['employee'], $ids['scoped'], true, (string) Str::ulid()));

    AccessSeed::connection()->table('branches')->where('id', $ids['branch'])->update(['is_active' => false]);

    AccessRefusals::expect(RefusalReason::BranchInactive, fn () => setEmployeePermission($ids['employee'], $ids['scoped'], true, $ids['branch']));
    AccessRefusals::expect(RefusalReason::BranchInactive, fn () => setEmployeePermission($ids['employee'], $ids['scoped'], false, $ids['branch']));

    expect(personalRows($ids['employee']))->toBe([]);

    // Control: reactivated, the identical request is accepted.
    AccessSeed::connection()->table('branches')->where('id', $ids['branch'])->update(['is_active' => true]);

    setEmployeePermission($ids['employee'], $ids['scoped'], true, $ids['branch']);

    expect(personalRows($ids['employee']))->toHaveCount(1);
});
