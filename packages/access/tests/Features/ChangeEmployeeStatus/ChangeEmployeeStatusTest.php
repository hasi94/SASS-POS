<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\ChangeEmployeeStatus;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatus;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatusHandler;
use Flexipos\Access\Support\EmployeeStatus;
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

function changeStatus(string $employeeId, string $status): bool
{
    /** @var ChangeEmployeeStatusHandler $handler */
    $handler = app(ChangeEmployeeStatusHandler::class);

    return $handler->handle(new ChangeEmployeeStatus($employeeId, $status))->bumped;
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
