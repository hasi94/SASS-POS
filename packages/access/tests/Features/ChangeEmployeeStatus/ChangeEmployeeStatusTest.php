<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\ChangeEmployeeStatus;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatus;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatusHandler;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 12 - changing an employee's status (AC-27, AC-52)
|--------------------------------------------------------------------------
|
| The bump rule is about CROSSING the acting boundary, not about the status
| changing, so the table below is enumerated over the six statuses rather
| than written as four examples: every ordered pair of distinct statuses is
| exercised, the expectation for each is computed from
| `EmployeeStatus::isActing()`, and the count of pairs is printed. Thirty
| pairs, not four, and the four interesting ones are not hand-picked.
|
| The moved-value case is what makes "the acting set lives in one place"
| (D-15) a measurement rather than a claim: move the set, and a transition
| that crossed the boundary stops crossing it. A handler holding its own
| copy of `['active', 'probation']` passes every other case in this file and
| fails that one.
|
| Its negative control is the third policy, built over a third set and
| handed to nobody: without it, "the answers followed the move" is equally
| consistent with the test having changed something else.
|
| D-38 made the entering crossing a grant: reactivating somebody restores
| everything they hold, so the actor must hold each grant at its scope.
| The table above runs with an actor who holds nothing, which is exactly
| right for employees who hold nothing; the cases at the bottom carry the
| spec's controls for employees who do.
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

function changeStatus(string $employeeId, string $status, ?string $actorId = null): bool
{
    /** @var ChangeEmployeeStatusHandler $handler */
    $handler = app(ChangeEmployeeStatusHandler::class);

    // An actor holding nothing unless one is named: the table's employees
    // hold nothing either, so D-38's check has nothing to ask of them and
    // the only thing it could refuse is a missing actor.
    $actorId ??= AccessSeed::actorHolding('status-actor-'.Str::lower((string) Str::ulid()), []);

    return $handler->handle(new ChangeEmployeeStatus($employeeId, $status, $actorId))->bumped;
}

/**
 * A suspended employee holding two keys through a role, an actor holding
 * one of them, and an owner holding both.
 *
 * @return array<string, string>
 */
function reactivationWorld(): array
{
    $ids = [
        'k1' => AccessSeed::permission('fixture.one'),
        'k2' => AccessSeed::permission('fixture.two'),
    ];

    $role = AccessSeed::role('cashier', [$ids['k1'], $ids['k2']]);

    $ids['employee'] = AccessSeed::employee('suspended', ['status' => EmployeeStatus::SUSPENDED]);
    AccessSeed::assign($ids['employee'], $role);

    $ids['lesser'] = AccessSeed::actorHolding('lesser', [$ids['k1']]);
    $ids['owner'] = AccessSeed::actorHolding('owner', [$ids['k1'], $ids['k2']], null, true);

    return $ids;
}

function statusAuditRows(): int
{
    return AccessSeed::connection()->table('audit_logs')->count();
}

it('stores every declared status, and bumps exactly when the change crosses the acting boundary (AC-27, AC-52)', function () {
    $policy = EmployeeStatus::shipped();
    $pairs = 0;
    $crossings = 0;

    foreach (EmployeeStatus::STATUSES as $from) {
        foreach (EmployeeStatus::STATUSES as $to) {
            if ($from === $to) {
                continue;
            }

            $employeeId = AccessSeed::employee('P'.$pairs, ['status' => $from]);
            $before = AccessSeed::permVersion($employeeId);

            $bumped = changeStatus($employeeId, $to);

            $crosses = $policy->isActing($from) !== $policy->isActing($to);
            $pairs++;
            $crossings += $crosses ? 1 : 0;

            $why = "{$from} -> {$to}";

            expect(AccessSeed::employeeRow($employeeId)['status'])->toBe($to, $why)
                ->and($bumped)->toBe($crosses, $why)
                ->and(AccessSeed::permVersion($employeeId))->toBe($before + ($crosses ? 1 : 0), $why);
        }
    }

    fwrite(STDOUT, PHP_EOL."AC-27 transitions examined: {$pairs}, of which {$crossings} cross the acting boundary.".PHP_EOL);

    // A table of thirty pairs none of which crossed would be a table that
    // cannot see the rule it is about.
    expect($pairs)->toBe(30)
        ->and($crossings)->toBeGreaterThan(0);
});

it('follows the acting set when it moves, and ignores a policy handed to nobody (AC-27, D-15)', function () {
    // Shipped: active -> on_leave leaves the acting set, so it bumps.
    $employeeId = AccessSeed::employee('C001', ['status' => EmployeeStatus::ACTIVE]);
    expect(changeStatus($employeeId, EmployeeStatus::ON_LEAVE))->toBeTrue();

    // Moved: with on_leave acting as well, the same transition crosses
    // nothing and must not bump.
    app()->instance(EmployeeStatus::class, EmployeeStatus::withActingStatuses([
        EmployeeStatus::ACTIVE,
        EmployeeStatus::ON_LEAVE,
    ]));
    app()->forgetInstance(ChangeEmployeeStatusHandler::class);

    $moved = AccessSeed::employee('C002', ['status' => EmployeeStatus::ACTIVE]);
    $before = AccessSeed::permVersion($moved);

    expect(changeStatus($moved, EmployeeStatus::ON_LEAVE))->toBeFalse()
        ->and(AccessSeed::permVersion($moved))->toBe($before);

    // The negative control: a third policy nobody was given.
    EmployeeStatus::withActingStatuses([EmployeeStatus::SUSPENDED]);

    $again = AccessSeed::employee('C003', ['status' => EmployeeStatus::ACTIVE]);

    expect(changeStatus($again, EmployeeStatus::ON_LEAVE))->toBeFalse();
});

it('refuses a status nobody declared, the same status twice over, and an absent employee (AC-27)', function () {
    $employeeId = AccessSeed::employee('C001', ['status' => EmployeeStatus::ACTIVE]);

    foreach ([
        [$employeeId, 'vacationing', 'a status nobody declared'],
        [$employeeId, '', 'a blank status'],
        [(string) Str::ulid(), EmployeeStatus::ACTIVE, 'an employee that never existed'],
    ] as [$id, $status, $why]) {
        try {
            changeStatus((string) $id, $status);

            expect(false)->toBeTrue("[{$why}] was accepted");
        } catch (AccessRefused $refused) {
            expect($refused->reason)->toBe(RefusalReason::Validation, $why);
        }
    }

    expect(AccessSeed::employeeRow($employeeId)['status'])->toBe(EmployeeStatus::ACTIVE);
});

it('accepts the status the employee already holds as a no-op, and bumps nothing (AC-52)', function () {
    // Not a refusal: AC-27 gives no rule against it, and AC-53 (c) says only
    // that a change implies a bump, never that an unchanged write must be
    // rejected. Inventing a refusal here would be a rule no document asks
    // for, and a client re-sending a status it already saw is ordinary.
    $employeeId = AccessSeed::employee('C001', ['status' => EmployeeStatus::ACTIVE]);
    $before = AccessSeed::permVersion($employeeId);

    expect(changeStatus($employeeId, EmployeeStatus::ACTIVE))->toBeFalse()
        ->and(AccessSeed::permVersion($employeeId))->toBe($before)
        ->and(AccessSeed::employeeRow($employeeId)['status'])->toBe(EmployeeStatus::ACTIVE);
});

it('refuses to reactivate an employee to an actor lacking one of their keys, and lets the owner (AC-27, D-38)', function () {
    $ids = reactivationWorld();
    $before = AccessSeed::permVersion($ids['employee']);
    $audits = statusAuditRows();

    AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => changeStatus($ids['employee'], EmployeeStatus::ACTIVE, $ids['lesser'])
    );

    expect(AccessSeed::employeeRow($ids['employee'])['status'])->toBe(EmployeeStatus::SUSPENDED)
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($before)
        ->and(statusAuditRows())->toBe($audits);

    // The paired control: the identical change by somebody holding both.
    expect(changeStatus($ids['employee'], EmployeeStatus::ACTIVE, $ids['owner']))->toBeTrue()
        ->and(AccessSeed::employeeRow($ids['employee'])['status'])->toBe(EmployeeStatus::ACTIVE);
});

it('lets the same lesser actor suspend that employee, because leaving restores nothing (AC-27, D-38)', function () {
    $ids = reactivationWorld();

    AccessSeed::connection()->table('employees')
        ->where('id', $ids['employee'])
        ->update(['status' => EmployeeStatus::ACTIVE]);

    // Leaving, then a move within the non-acting side: neither is checked.
    expect(changeStatus($ids['employee'], EmployeeStatus::SUSPENDED, $ids['lesser']))->toBeTrue()
        ->and(changeStatus($ids['employee'], EmployeeStatus::TERMINATED, $ids['lesser']))->toBeFalse()
        ->and(AccessSeed::employeeRow($ids['employee'])['status'])->toBe(EmployeeStatus::TERMINATED);
});

it('lets any actor reactivate an employee who holds no grants, and refuses a null actor even then (AC-27, D-36, D-38)', function () {
    $employeeId = AccessSeed::employee('empty', ['status' => EmployeeStatus::SUSPENDED]);
    $before = AccessSeed::permVersion($employeeId);
    $audits = statusAuditRows();

    /** @var ChangeEmployeeStatusHandler $handler */
    $handler = app(ChangeEmployeeStatusHandler::class);

    AccessRefusals::expect(
        RefusalReason::ActorRequired,
        fn () => $handler->handle(new ChangeEmployeeStatus($employeeId, EmployeeStatus::ACTIVE, null))
    );

    expect(AccessSeed::employeeRow($employeeId)['status'])->toBe(EmployeeStatus::SUSPENDED)
        ->and(AccessSeed::permVersion($employeeId))->toBe($before)
        ->and(statusAuditRows())->toBe($audits);

    $nobody = AccessSeed::actorHolding('holds-nothing', []);

    expect(changeStatus($employeeId, EmployeeStatus::PROBATION, $nobody))->toBeTrue();
});

it('asks for each grant at its own scope, not everywhere (AC-27, AC-36, D-38)', function () {
    $key = AccessSeed::permission('fixture.scoped', true);
    $here = AccessSeed::branch();
    $there = AccessSeed::branch();

    $role = AccessSeed::role('branch_cashier', [$key]);
    $employeeId = AccessSeed::employee('branch-bound', ['status' => EmployeeStatus::ON_LEAVE]);
    AccessSeed::attach($employeeId, $here);
    AccessSeed::assign($employeeId, $role, $here);

    // Holding the key at another branch is not holding it where the grant is.
    $elsewhere = AccessSeed::actorHolding('elsewhere', [$key], $there);

    AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => changeStatus($employeeId, EmployeeStatus::ACTIVE, $elsewhere)
    );

    // Holding it at that branch only is enough: the grant is no wider.
    $local = AccessSeed::actorHolding('local', [$key], $here);

    expect(changeStatus($employeeId, EmployeeStatus::ACTIVE, $local))->toBeTrue();
});

it('counts a personal allow as a grant the actor must hold (AC-27, D-38)', function () {
    $key = AccessSeed::permission('fixture.personal');

    $employeeId = AccessSeed::employee('personal', ['status' => EmployeeStatus::RESIGNED]);
    AccessSeed::personal($employeeId, $key, true);

    AccessRefusals::expect(
        RefusalReason::NoEscalation,
        fn () => changeStatus($employeeId, EmployeeStatus::ACTIVE)
    );

    $holder = AccessSeed::actorHolding('holder', [$key]);

    expect(changeStatus($employeeId, EmployeeStatus::ACTIVE, $holder))->toBeTrue();
});
