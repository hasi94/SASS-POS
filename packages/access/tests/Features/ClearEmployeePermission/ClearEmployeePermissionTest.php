<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\ClearEmployeePermission;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermission;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermissionHandler;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermission;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermissionHandler;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Database\Events\QueryExecuted;
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
| D-37 bounds clearing a DENY as AC-36 bounds an allow, so every case names
| its actor. The four paired controls of the amended AC-33 are at the end:
| a lesser actor against the owner, a null actor, a lesser actor clearing
| an allow, and the clear agreeing with the replace-by-allow route.
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

function clearEmployeePermission(string $employeeId, string $key, ?string $branchId, ?string $actorId): void
{
    /** @var ClearEmployeePermissionHandler $handler */
    $handler = app(ClearEmployeePermissionHandler::class);

    $handler->handle(new ClearEmployeePermission($employeeId, $key, $branchId, $actorId));
}

function clearAuditRows(): int
{
    return AccessSeed::connection()->table('audit_logs')->count();
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

    // D-37: clearing a deny is bounded as an allow is, so every clear here
    // is made by somebody who holds the key everywhere - the owner - unless
    // a case names a lesser actor. The lesser one holds an unrelated key, so
    // a refusal is about K and not about holding nothing at all.
    $ids['owner'] = AccessSeed::actorHolding('owner', [$ids['key']], null, true);
    $ids['other'] = AccessSeed::permission('fixture.other');
    $ids['lesser'] = AccessSeed::actorHolding('lesser', [$ids['other']]);

    return $ids;
}

it('clears the deny at the all-branches scope and leaves the branch allow (AC-33, AC-52)', function () {
    $ids = clearPermissionWorld();
    $before = AccessSeed::permVersion($ids['employee']);

    clearEmployeePermission($ids['employee'], $ids['key'], null, $ids['owner']);

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([$ids['branch']])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before + 1);
});

it('clears the allow at the branch and leaves the all-branches deny (AC-33)', function () {
    $ids = clearPermissionWorld();

    clearEmployeePermission($ids['employee'], $ids['key'], $ids['branch'], $ids['owner']);

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([null]);
});

it('refuses to clear a row that is not there and bumps nothing (AC-33)', function () {
    $ids = clearPermissionWorld();

    clearEmployeePermission($ids['employee'], $ids['key'], null, $ids['owner']);

    $before = AccessSeed::permVersion($ids['employee']);

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => clearEmployeePermission($ids['employee'], $ids['key'], null, $ids['owner'])
    );

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([$ids['branch']])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before);
});

it('refuses an unregistered key the same way an absent row is refused (AC-33)', function () {
    $ids = clearPermissionWorld();

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => clearEmployeePermission($ids['employee'], 'fixture.never_declared', null, $ids['owner'])
    );

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([null, $ids['branch']]);
});

it('refuses an actor lacking K clearing a deny of K, and lets the owner (AC-33 control 1, D-37)', function () {
    $ids = clearPermissionWorld();
    $before = AccessSeed::permVersion($ids['employee']);
    $audits = clearAuditRows();

    AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => clearEmployeePermission($ids['employee'], $ids['key'], null, $ids['lesser'])
    );

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([null, $ids['branch']])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before)
        ->and(clearAuditRows())->toBe($audits);

    clearEmployeePermission($ids['employee'], $ids['key'], null, $ids['owner']);

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([$ids['branch']])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before + 1)
        ->and(clearAuditRows())->toBe($audits + 1);
});

it('refuses a null actor clearing a deny as actor_required, with the same effects (AC-33 control 2, D-36, D-37)', function () {
    $ids = clearPermissionWorld();
    $before = AccessSeed::permVersion($ids['employee']);
    $audits = clearAuditRows();

    AccessRefusals::expect(
        RefusalReason::ActorRequired,
        fn () => clearEmployeePermission($ids['employee'], $ids['key'], null, null)
    );

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([null, $ids['branch']])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before)
        ->and(clearAuditRows())->toBe($audits);
});

it('lets an actor lacking K clear an allow of K, with the bump (AC-33 control 3, D-37)', function () {
    $ids = clearPermissionWorld();
    $before = AccessSeed::permVersion($ids['employee']);

    clearEmployeePermission($ids['employee'], $ids['key'], $ids['branch'], $ids['lesser']);

    expect(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([null])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before + 1);
});

it('answers clearing a deny exactly as replacing it with an allow, for both actors (AC-33 control 4, D-37)', function () {
    $ids = clearPermissionWorld();

    // A second employee in the same state, so each route starts from it.
    $twin = AccessSeed::employee('twin');
    AccessSeed::personal($twin, $ids['key'], false);

    /** @var SetEmployeePermissionHandler $set */
    $set = app(SetEmployeePermissionHandler::class);

    $outcomes = [];

    foreach (['lesser', 'owner'] as $actor) {
        $viaClear = null;
        $viaAllow = null;

        try {
            clearEmployeePermission($ids['employee'], $ids['key'], null, $ids[$actor]);
        } catch (AccessRefused $refused) {
            $viaClear = $refused->reason;
        }

        try {
            $set->handle(new SetEmployeePermission($twin, $ids['key'], true, null, $ids[$actor]));
        } catch (AccessRefused $refused) {
            $viaAllow = $refused->reason;
        }

        $outcomes[$actor] = [$viaClear, $viaAllow];
    }

    // Both routes agree for each actor, and the two actors are answered
    // differently, so agreement is not two routes that always refuse.
    expect($outcomes['lesser'])->toBe([RefusalReason::NoEscalation, RefusalReason::NoEscalation])
        ->and($outcomes['owner'])->toBe([null, null]);
});

it('deletes only the row it judged, so an allow swapped for a deny mid-way is not lifted unchecked (D-37, review W-6)', function () {
    $ids = clearPermissionWorld();
    $connection = AccessSeed::connection();
    $before = AccessSeed::permVersion($ids['employee']);
    $audits = clearAuditRows();

    // The swap another administrator would make, played between the
    // handler's judging read and its delete: the moment the read of
    // `employee_permissions` has run, the branch allow becomes a deny.
    // It runs inside the handler's transaction, so the refusal's rollback
    // also undoes it; what is asserted is what the delete did meanwhile.
    $flipped = false;
    $deletes = [];

    $connection->listen(function (QueryExecuted $query) use ($connection, $ids, &$flipped, &$deletes): void {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'delete') && str_contains($sql, 'employee_permissions')) {
            $deletes[] = $query->sql;
        }

        if ($flipped || ! str_starts_with($sql, 'select') || ! str_contains($sql, '"allowed" from "employee_permissions"')) {
            return;
        }

        $flipped = true;

        $connection->table('employee_permissions')
            ->where('employee_id', $ids['employee'])
            ->where('permission_key', $ids['key'])
            ->where('branch_id', $ids['branch'])
            ->update(['allowed' => false]);
    });

    // The lesser actor lacks K: clearing the allow passes D-37, clearing the
    // deny it has become would not. The judged row is gone, so: validation.
    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => clearEmployeePermission($ids['employee'], $ids['key'], $ids['branch'], $ids['lesser'])
    );

    fwrite(STDOUT, "\nW-6 delete statements:\n".implode("\n", $deletes)."\n");

    // Liveness: the swap happened, and the delete ran after it.
    expect($flipped)->toBeTrue()
        ->and($deletes)->toHaveCount(1)
        ->and(strtolower($deletes[0]))->toContain('"allowed" = ?')
        // Nothing changed: both rows as seeded, no bump, no audit row.
        ->and(remainingPersonalScopes($ids['employee'], $ids['key']))->toBe([null, $ids['branch']])
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before)
        ->and(clearAuditRows())->toBe($audits);
});
