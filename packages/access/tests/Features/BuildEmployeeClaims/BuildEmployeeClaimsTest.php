<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\BuildEmployeeClaims;

use Flexipos\Access\Features\BuildEmployeeClaims\BuildEmployeeClaims;
use Flexipos\Access\Features\BuildEmployeeClaims\BuildEmployeeClaimsHandler;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\PermHash;
use Flexipos\Access\Support\ScopeKey;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\EmployeeClaims;
use Flexipos\Contracts\Access\EmployeeClaimSource;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 22 - the five employee-derived claims
|--------------------------------------------------------------------------
|
| AC-55 (an employee with no contributions still has a defined hash),
| AC-57 (`branch_ids` and `all_branches`, in both senses) and AC-61 (D-6's
| four causes, each with its own positive control).
|
| **AC-57's four employees are four distinct answers on purpose.** The
| criterion names them and the reason is in the criterion: a constant
| implementation - always `[]`, always false, always the attachment list -
| agrees with one of the four and is caught by the other three. They are
| asserted in one case over one seeding so that the four are compared with
| each other rather than each with itself.
|
| **The deny is the discriminating input for `all_branches`.** An employee
| holding a null-scope *deny* and nothing else has a contribution at the
| null scope, so an implementation reading only the scope says "all
| branches" about somebody who may do nothing anywhere. That case is in the
| table below and is the one that separates the two readings.
|
| **AC-61's four causes each get their positive control, in the same
| case.** Fixing the single cause makes the identical call succeed, which
| is what attributes the refusal to that cause and not to the fixture
| being broken in some other way.
|
| Every case runs against step 5's fixture tenant, entered through Tenancy
| with its foreign keys enforced (Ruling 7), so a seeding the schema would
| refuse fails here rather than becoming the input to a green test.
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

function claimsHandler(): BuildEmployeeClaimsHandler
{
    /** @var BuildEmployeeClaimsHandler $handler */
    $handler = app(BuildEmployeeClaimsHandler::class);

    return $handler;
}

/**
 * An employee linked to a master user of their own.
 *
 * **One master user per employee, because the column says so.**
 * `employees.user_id` is unique: a shop's staff map one-to-one onto master
 * accounts, and a fixture linking two employees to one user is a row the
 * schema refuses - measured, the first version of this file did exactly
 * that and failed on the second insert. So each seeded employee gets a
 * fresh id, and the cases ask as that employee's own user, which is also
 * what D-6's fourth check compares.
 *
 * @param  array<string, mixed>  $overrides
 */
function claimsEmployee(string $code, array $overrides = []): string
{
    $userId = (string) Str::ulid();
    $employeeId = AccessSeed::employee($code, array_merge(['user_id' => $userId], $overrides));

    claimsUsers()[$employeeId] = $userId;

    return $employeeId;
}

/**
 * The employee-to-user map this file seeds, by reference.
 *
 * A static inside a function rather than a global: Pest collects every
 * test file into one process, so a `global` here would be a name every
 * other file shares.
 *
 * @return array<string, string>
 */
function &claimsUsers(): array
{
    static $users = [];

    return $users;
}

/**
 * The master user one seeded employee is linked to.
 */
function claimsUserOf(string $employeeId): string
{
    return claimsUsers()[$employeeId] ?? (string) Str::ulid();
}

it('is bound to the published contract, so authentication reaches it without the package', function () {
    expect(app(EmployeeClaimSource::class))->toBeInstanceOf(BuildEmployeeClaimsHandler::class);
});

it('answers the four employees of AC-57 with four different claims', function () {
    $branchX = AccessSeed::branch();
    $branchY = AccessSeed::branch();

    AccessSeed::permission('fixture.company');

    $owner = AccessSeed::role('fixture_owner', ['fixture.company']);
    $local = AccessSeed::role('fixture_local', ['fixture.company']);

    // 1 - attachments {X, Y} and no null-scope allow.
    $attachedOnly = claimsEmployee('attached-only');
    AccessSeed::attach($attachedOnly, $branchX);
    AccessSeed::attach($attachedOnly, $branchY);
    AccessSeed::assign($attachedOnly, $local, $branchX);

    // 2 - no attachments and an owner role at the null scope.
    $ownerEverywhere = claimsEmployee('owner-everywhere');
    AccessSeed::assign($ownerEverywhere, $owner);

    // 3 - attachments {X} and a role at X only.
    $atX = claimsEmployee('at-x');
    AccessSeed::attach($atX, $branchX);
    AccessSeed::assign($atX, $local, $branchX);

    // 4 - neither.
    $neither = claimsEmployee('neither');

    $sorted = [$branchX, $branchY];
    sort($sorted);

    $answers = [];

    foreach ([
        'attachedOnly' => $attachedOnly,
        'ownerEverywhere' => $ownerEverywhere,
        'atX' => $atX,
        'neither' => $neither,
    ] as $name => $id) {
        $claims = claimsHandler()->handle(new BuildEmployeeClaims($id, claimsUserOf($id)));

        $answers[$name] = [$claims->branchIds, $claims->allBranches];
    }

    expect($answers)->toBe([
        'attachedOnly' => [$sorted, false],
        'ownerEverywhere' => [[], true],
        'atX' => [[$branchX], false],
        'neither' => [[], false],
    ]);

    // The four are distinct from each other, which is what makes a
    // constant implementation fail rather than merely disagree once.
    expect(array_values(array_unique(array_map(
        static fn (array $answer): string => (string) json_encode($answer),
        $answers
    ))))->toHaveCount(4);
});

it('reads all_branches from the allowing contribution, never from the scope alone (AC-57)', function () {
    AccessSeed::permission('fixture.company');

    // A null-scope DENY and nothing else: a contribution at the null
    // scope, and the answer is false. An implementation that asked "is
    // there a contribution at `*`" says true here and is wrong about
    // somebody who may do nothing at all.
    $deniedEverywhere = claimsEmployee('denied-everywhere');
    AccessSeed::personal($deniedEverywhere, 'fixture.company', false);

    // The control: the same shape with `allowed` true.
    $allowedEverywhere = claimsEmployee('allowed-everywhere');
    AccessSeed::personal($allowedEverywhere, 'fixture.company', true);

    expect(claimsHandler()->handle(new BuildEmployeeClaims($deniedEverywhere, claimsUserOf($deniedEverywhere)))->allBranches)->toBeFalse()
        ->and(claimsHandler()->handle(new BuildEmployeeClaims($allowedEverywhere, claimsUserOf($allowedEverywhere)))->allBranches)->toBeTrue();
});

it('never lists a branch the employee is not attached to, even when all_branches is true (AC-57, D-2)', function () {
    $branchX = AccessSeed::branch();

    AccessSeed::permission('fixture.company');

    $employeeId = claimsEmployee('owner');
    AccessSeed::assign($employeeId, AccessSeed::role('fixture_owner', ['fixture.company']));

    // Attached nowhere, allowed everywhere: `branch_ids` stays empty.
    // Expanding it would need a `branches` table, which does not exist,
    // and would redefine the claim the day FP-209 creates one.
    expect(claimsHandler()->handle(new BuildEmployeeClaims($employeeId, claimsUserOf($employeeId)))->branchIds)->toBe([]);

    AccessSeed::attach($employeeId, $branchX);

    expect(claimsHandler()->handle(new BuildEmployeeClaims($employeeId, claimsUserOf($employeeId)))->branchIds)->toBe([$branchX]);
});

it('carries the stored version and the independently recomputed hash (AC-54, AC-56)', function () {
    $branchX = AccessSeed::branch();

    AccessSeed::permission('fixture.scoped', true);

    $employeeId = claimsEmployee('cashier');
    AccessSeed::attach($employeeId, $branchX);
    AccessSeed::assign($employeeId, AccessSeed::role('fixture_till', ['fixture.scoped']), $branchX);
    AccessSeed::personal($employeeId, 'fixture.scoped', false, $branchX);

    AccessSeed::connection()->table('employees')->where('id', $employeeId)->update(['perm_version' => 9]);

    $claims = claimsHandler()->handle(new BuildEmployeeClaims($employeeId, claimsUserOf($employeeId)));

    // Recomputed here from the canonical form of Ruling 6 rather than by
    // calling the shipped helper: the hash a till has to reproduce is a
    // published format, and a test that called `PermHash` would agree
    // with any format the helper happened to use.
    $expected = bin2hex(hash('sha256', implode("\n", [
        PermHash::TAG,
        '9',
        implode("\n", [
            'allow'."\t".'fixture.scoped'."\t".$branchX,
            'deny'."\t".'fixture.scoped'."\t".$branchX,
        ]),
    ]), true));

    expect($claims->permVersion)->toBe(9)
        ->and($claims->permHash)->toBe($expected)
        ->and($claims->permHash)->toHaveLength(EmployeeClaims::HASH_LENGTH);
});

it('still defines a hash for an employee with no contributions at all (AC-55)', function () {
    $employeeId = claimsEmployee('new-starter');

    $claims = claimsHandler()->handle(new BuildEmployeeClaims($employeeId, claimsUserOf($employeeId)));

    expect($claims->permHash)->toBe(PermHash::compute($claims->permVersion, []))
        ->and($claims->permHash)->toHaveLength(EmployeeClaims::HASH_LENGTH)
        ->and($claims->branchIds)->toBe([])
        ->and($claims->allBranches)->toBeFalse();
});

it('refuses each of D-6 s four causes, and succeeds the moment each is fixed (AC-61)', function () {
    $asker = (string) Str::ulid();

    $missing = (string) Str::ulid();
    $deleted = AccessSeed::employee('deleted', ['user_id' => $asker, 'deleted_at' => '2026-10-20 09:00:00.000']);
    $onLeave = claimsEmployee('on-leave', ['status' => EmployeeStatus::ON_LEAVE]);
    $unlinked = AccessSeed::employee('unlinked');
    $someoneElses = claimsEmployee('someone-elses');

    // Each cause is asked about by the user the fixture links it to, so
    // that only the cause under test differs. `$asker` is the user for
    // the three cases that are not about the link itself.
    $causes = [
        'no row' => [$missing, $asker],
        'soft-deleted' => [$deleted, $asker],
        'not acting' => [$onLeave, claimsUserOf($onLeave)],
        'null user_id' => [$unlinked, $asker],
        'another user id' => [$someoneElses, $asker],
    ];

    foreach ($causes as $why => [$employeeId, $userId]) {
        AccessRefusals::expect(
            RefusalReason::EmployeeUnavailable,
            fn () => claimsHandler()->handle(new BuildEmployeeClaims($employeeId, $userId)),
            $why
        );
    }

    // The positive control for each, one cause at a time. Without these
    // the five refusals above are equally consistent with a handler that
    // refuses everybody.
    AccessSeed::connection()->table('employees')->where('id', $deleted)->update(['deleted_at' => null]);
    AccessSeed::connection()->table('employees')->where('id', $onLeave)->update(['status' => EmployeeStatus::ACTIVE]);

    expect(claimsHandler()->handle(new BuildEmployeeClaims($deleted, $asker))->employee)->toBe($deleted)
        ->and(claimsHandler()->handle(new BuildEmployeeClaims($onLeave, claimsUserOf($onLeave)))->employee)->toBe($onLeave)
        // The two link cases are fixed by asking as the user the row
        // names, which is the same repair an operator makes to a wrong
        // `tenant_users.employee_id`.
        ->and(claimsHandler()->handle(new BuildEmployeeClaims($someoneElses, claimsUserOf($someoneElses)))->employee)
        ->toBe($someoneElses);

    // Its own user, not `$asker`: `$deleted` already holds that one and
    // the column is unique, so reusing it would fail the fixture rather
    // than the criterion.
    $linkedUser = (string) Str::ulid();

    AccessSeed::connection()->table('employees')->where('id', $unlinked)->update(['user_id' => $linkedUser]);

    expect(claimsHandler()->handle(new BuildEmployeeClaims($unlinked, $linkedUser))->employee)->toBe($unlinked);

    // The one cause that cannot be fixed: there is no row to repair.
    AccessRefusals::expect(
        RefusalReason::EmployeeUnavailable,
        fn () => claimsHandler()->handle(new BuildEmployeeClaims($missing, $asker))
    );
});

it('reads the acting set from the one authority, and follows it when it moves (D-15)', function () {
    $onLeave = claimsEmployee('on-leave', ['status' => EmployeeStatus::ON_LEAVE]);
    $active = claimsEmployee('active');

    AccessRefusals::expect(
        RefusalReason::EmployeeUnavailable,
        fn () => claimsHandler()->handle(new BuildEmployeeClaims($onLeave, claimsUserOf($onLeave)))
    );

    app()->instance(EmployeeStatus::class, EmployeeStatus::withActingStatuses([EmployeeStatus::ON_LEAVE]));

    expect(claimsHandler()->handle(new BuildEmployeeClaims($onLeave, claimsUserOf($onLeave)))->employee)->toBe($onLeave);

    // The other direction in the same move: `active` is no longer in the
    // set, so the handler is reading the authority rather than holding a
    // copy that happens to agree with it.
    AccessRefusals::expect(
        RefusalReason::EmployeeUnavailable,
        fn () => claimsHandler()->handle(new BuildEmployeeClaims($active, claimsUserOf($active)))
    );
});

it('reads a contribution the way the hash does, from one definition (D-23)', function () {
    $branchX = AccessSeed::branch();

    AccessSeed::permission('fixture.company');

    $employeeId = claimsEmployee('owner');
    AccessSeed::attach($employeeId, $branchX);
    AccessSeed::assign($employeeId, AccessSeed::role('fixture_owner', ['fixture.company']));

    $contributions = PermHash::contributionsFor(AccessSeed::connection(), $employeeId);

    // `all_branches` is a statement about the same list the hash is
    // computed from, which is why it is read from that list and not from
    // a second pair of queries: two readings of "a contribution" could
    // disagree about a soft-deleted role or about which tables count.
    expect($contributions)->toBe([[true, 'fixture.company', ScopeKey::ALL_BRANCHES]])
        ->and(claimsHandler()->handle(new BuildEmployeeClaims($employeeId, claimsUserOf($employeeId)))->allBranches)->toBeTrue();
});
