<?php

use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttemptHandler;
use Flexipos\Auth\Support\IpAddress;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 7: the one writer of login_history and security_events
|--------------------------------------------------------------------------
|
| AC-23, AC-24, AC-25 and AC-27.
|
| **This slice is the instrument the rest of the task is measured with.** D-12
| gives an unknown email, a wrong password, a locked account, an inactive
| account and a soft-deleted account one byte-identical 401, so a later test
| asserting the 401 proves only that *something* refused - five mechanisms can
| produce it. `login_history.failure_reason` is the one place the cause
| survives, which is why this file is written before `Login` and why it spends
| as much effort on "a wrong reason cannot be written" as on "a row is
| written": a reason that could be mistyped or inverted is an instrument that
| reports success for the wrong mechanism.
|
| **AC-27 is graded corroborating, not independently proven.** The column is
| `VARBINARY(16)` on the engine only; the stand-in stores a BLOB of whatever
| width it is handed, so what is proved here is that one column holds both
| families and that the value round-trips unchanged - not that the engine
| enforces the width. And the assertion is always on the PHP value after
| `inet_ntop`: step 2(b) measured SQLite's `length()` and `typeof()` reporting
| `1` and `text` for a packed address, because both truncate at the first NUL
| byte. No assertion in this file reads `length(ip)` in SQL.
|
| The master is the suite's in-memory SQLite stand-in, asserted to be SQLite
| before anything migrates. Nothing here reaches the engine at
| 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    authFixtureClockAt('2026-10-15 09:00:00.000');
    authFixtureMigrateMaster();
});

/**
 * The handler, built by the container so the bindings under test are shipped
 * ones.
 */
function attemptHandler(): RecordLoginAttemptHandler
{
    return app(RecordLoginAttemptHandler::class);
}

/**
 * Every `login_history` row, as arrays, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function attemptRows(): array
{
    return authFixtureRows('login_history');
}

/**
 * The single `login_history` row, with its failure explained when there is not
 * exactly one - "exactly one row" is half of AC-23.
 *
 * @return array<string, mixed>
 */
function attemptRow(): array
{
    $rows = attemptRows();

    expect($rows)->toHaveCount(1);

    return $rows[0];
}

/*
|--------------------------------------------------------------------------
| AC-23: the row, its shape, and the cause it carries
|--------------------------------------------------------------------------
*/

it('writes exactly one row for a successful login, in the shape AC-23 names', function () {
    attemptHandler()->handle(RecordLoginAttempt::succeeded(
        email: 'Owner@Example.com',
        userId: '01HQ0000000000000000000001',
        ip: '203.0.113.7',
        userAgent: 'FlexiPOS/1.0',
        deviceId: 'till-3',
    ));

    $row = attemptRow();

    expect($row['user_id'])->toBe('01HQ0000000000000000000001')
        ->and($row['tenant_id'])->toBeNull()
        // The display form, not the lookup key: `users.email_lower` is what a
        // lookup uses, and this column is evidence of what was typed.
        ->and($row['email'])->toBe('Owner@Example.com')
        ->and($row['principal_type'])->toBe('user')
        ->and((bool) $row['success'])->toBeTrue()
        ->and($row['failure_reason'])->toBeNull()
        ->and(IpAddress::unpack($row['ip']))->toBe('203.0.113.7')
        ->and($row['user_agent'])->toBe('FlexiPOS/1.0')
        ->and($row['device_id'])->toBe('till-3')
        // The injected clock, at millisecond precision, not the wall clock.
        ->and($row['created_at'])->toBe('2026-10-15 09:00:00.000');

    // Nothing notable happened, so nothing is in the table that exists for
    // things that are notable on their own.
    expect(authFixtureRows('security_events'))->toBe([]);
});

it('leaves user_id null when the submitted address matched nobody', function () {
    attemptHandler()->handle(RecordLoginAttempt::failed(
        email: 'nobody@example.com',
        failureReason: RecordLoginAttempt::REASON_UNKNOWN_USER,
        ip: '203.0.113.7',
    ));

    $row = attemptRow();

    expect($row['user_id'])->toBeNull()
        ->and($row['email'])->toBe('nobody@example.com')
        ->and((bool) $row['success'])->toBeFalse()
        ->and($row['failure_reason'])->toBe('unknown_user');
});

it('carries each cause into the column that later tests read it from', function (string $reason) {
    attemptHandler()->handle(RecordLoginAttempt::failed(
        email: 'owner@example.com',
        failureReason: $reason,
        userId: '01HQ0000000000000000000001',
    ));

    // The whole point of the slice: the cause the 401 refuses to disclose is
    // readable here, unchanged and untruncated by the 40-character column.
    expect(attemptRow()['failure_reason'])->toBe($reason);
})->with([
    RecordLoginAttempt::REASON_UNKNOWN_USER,
    RecordLoginAttempt::REASON_BAD_PASSWORD,
    RecordLoginAttempt::REASON_LOCKED,
    RecordLoginAttempt::REASON_NOT_ACTIVE,
    RecordLoginAttempt::REASON_NO_ACCESS,
    RecordLoginAttempt::REASON_TENANT_NOT_SERVABLE,
]);

it('records a tenant selection against the tenant it was about', function () {
    attemptHandler()->handle(RecordLoginAttempt::succeeded(
        email: 'owner@example.com',
        userId: '01HQ0000000000000000000001',
        tenantId: '01HQ00000000000000000000T1',
    ));

    expect(attemptRow()['tenant_id'])->toBe('01HQ00000000000000000000T1');
});

it('stores the address trimmed and in the case it was submitted', function () {
    attemptHandler()->handle(RecordLoginAttempt::failed(
        email: "  Owner@Example.com\t",
        failureReason: RecordLoginAttempt::REASON_BAD_PASSWORD,
    ));

    expect(attemptRow()['email'])->toBe('Owner@Example.com');
});

it('refuses a command whose two halves disagree, so the cause column stays readable', function () {
    // A row saying `success = true, failure_reason = bad_password`, or a
    // failure with a mistyped reason, would make the instrument unreadable in
    // exactly the cases it exists for. Both are refused at construction, where
    // there is no row yet to be wrong.
    expect(fn () => RecordLoginAttempt::failed('owner@example.com', 'bad_passord'))
        ->toThrow(InvalidArgumentException::class, 'unknown failure reason [bad_passord]')
        ->and(fn () => RecordLoginAttempt::failed('owner@example.com', ''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => RecordLoginAttempt::failed('   ', RecordLoginAttempt::REASON_LOCKED))
        ->toThrow(InvalidArgumentException::class, 'an attempt records the address that was submitted')
        ->and(fn () => RecordLoginAttempt::failed(
            email: 'owner@example.com',
            failureReason: RecordLoginAttempt::REASON_BAD_PASSWORD,
            securityEventType: 'lockuot',
        ))->toThrow(InvalidArgumentException::class, 'unknown security event type [lockuot]');

    // The subject is live: the same makers accept the shapes they are for, so
    // the four refusals above are not a constructor that refuses everything.
    expect(RecordLoginAttempt::succeeded('owner@example.com', '01HQ0000000000000000000001')->failureReason)->toBeNull()
        ->and(RecordLoginAttempt::failed('owner@example.com', RecordLoginAttempt::REASON_LOCKED)->failureReason)
        ->toBe('locked');

    expect(attemptRows())->toBe([]);
});

it('agrees with the refresh row about what a principal is', function () {
    // Two slices write a `principal_type` column and FP-209 adds the second
    // value to both. Pinned here rather than by one slice importing the other:
    // the day they disagree, a token and its history name different things.
    expect(RecordLoginAttemptHandler::PRINCIPAL_TYPE)->toBe(IssueTokenPairHandler::PRINCIPAL_TYPE)
        ->and(RecordLoginAttemptHandler::PRINCIPAL_TYPE)->toBe('user');
});

/*
|--------------------------------------------------------------------------
| AC-24: the row survives the error it is about
|--------------------------------------------------------------------------
*/

it('leaves the row behind when the caller throws after recording the attempt', function () {
    // The positive control the plan requires: a handler decorated to throw
    // after the attempt is recorded. A failed login ends in an error response,
    // and if the row were written inside a transaction somebody has to
    // remember to commit on every refusal branch, this is where it would
    // vanish.
    $decorated = function (RecordLoginAttempt $command): void {
        attemptHandler()->handle($command);

        throw new RuntimeException('the refusal is decided after the attempt is recorded');
    };

    expect(fn () => $decorated(RecordLoginAttempt::failed(
        email: 'owner@example.com',
        failureReason: RecordLoginAttempt::REASON_BAD_PASSWORD,
        userId: '01HQ0000000000000000000001',
    )))->toThrow(RuntimeException::class);

    expect(attemptRow()['failure_reason'])->toBe('bad_password');
});

it('writes outside any transaction of its own, and the probe can see one when there is one', function () {
    $levels = [];

    DB::connection('master')->listen(function () use (&$levels) {
        $levels[] = DB::connection('master')->transactionLevel();
    });

    attemptHandler()->handle(RecordLoginAttempt::failed(
        email: 'owner@example.com',
        failureReason: RecordLoginAttempt::REASON_BAD_PASSWORD,
    ));

    // Every statement this handler issued ran at transaction level zero: there
    // is nothing open for a later error to roll back, which is what makes
    // AC-24 a property of where the write sits.
    expect($levels)->not->toBe([])
        ->and(array_unique($levels))->toBe([0]);

    // And the control for the probe itself. Observing "level 0" would also be
    // what a probe that reads nothing reports, so the same observation is made
    // of a write the test deliberately wraps - it must report level 1. Only
    // then does the zero above mean the handler opened nothing.
    $wrapped = [];

    DB::connection('master')->listen(function () use (&$wrapped) {
        $wrapped[] = DB::connection('master')->transactionLevel();
    });

    DB::connection('master')->transaction(function () {
        attemptHandler()->handle(RecordLoginAttempt::failed(
            email: 'owner@example.com',
            failureReason: RecordLoginAttempt::REASON_LOCKED,
        ));
    });

    expect($wrapped)->toContain(1);
});

/*
|--------------------------------------------------------------------------
| AC-25: the lockout event beside the attempt that caused it
|--------------------------------------------------------------------------
*/

it('writes the lockout event beside the bad_password row that caused it', function () {
    attemptHandler()->handle(RecordLoginAttempt::failed(
        email: 'owner@example.com',
        failureReason: RecordLoginAttempt::REASON_BAD_PASSWORD,
        userId: '01HQ0000000000000000000001',
        ip: '203.0.113.7',
        securityEventType: RecordLoginAttempt::EVENT_LOCKOUT,
        securityEventDetail: ['threshold' => 5, 'locked_for_minutes' => 15],
    ));

    $events = authFixtureRows('security_events');

    expect(attemptRow()['failure_reason'])->toBe('bad_password')
        // Exactly one (AC-17): a lockout that raised two events would page
        // somebody twice for one account.
        ->and($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('lockout')
        ->and($events[0]['user_id'])->toBe('01HQ0000000000000000000001')
        ->and($events[0]['tenant_id'])->toBeNull()
        ->and($events[0]['actor_id'])->toBeNull()
        ->and(IpAddress::unpack($events[0]['ip']))->toBe('203.0.113.7')
        ->and($events[0]['created_at'])->toBe('2026-10-15 09:00:00.000')
        ->and(json_decode((string) $events[0]['detail'], true))
        ->toBe(['threshold' => 5, 'locked_for_minutes' => 15]);
});

it('writes no event for the four attempts before the fifth', function () {
    // AC-16 is AC-17's control and this is its half of it: the same handler,
    // the same reason, no event named - so the row above is caused by the
    // command naming an event and not by a failure of any kind.
    foreach (range(1, 4) as $ignored) {
        attemptHandler()->handle(RecordLoginAttempt::failed(
            email: 'owner@example.com',
            failureReason: RecordLoginAttempt::REASON_BAD_PASSWORD,
            userId: '01HQ0000000000000000000001',
        ));
    }

    expect(attemptRows())->toHaveCount(4)
        ->and(authFixtureRows('security_events'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| AC-27: one column, both families
|--------------------------------------------------------------------------
*/

it('round-trips an address of either family through the one binary column', function (string $address, int $width) {
    attemptHandler()->handle(RecordLoginAttempt::failed(
        email: 'owner@example.com',
        failureReason: RecordLoginAttempt::REASON_BAD_PASSWORD,
        ip: $address,
        securityEventType: RecordLoginAttempt::EVENT_LOCKOUT,
    ));

    $stored = attemptRow()['ip'];

    // Asserted on the PHP value, never through SQL: SQLite's length() and
    // typeof() truncate at the first NUL and would report 1 and text here
    // (step 2(b)). The width is checked in PHP, where the bytes are intact.
    expect(strlen((string) $stored))->toBe($width)
        ->and(IpAddress::unpack($stored))->toBe($address)
        // The same column in the other table, written in the same call.
        ->and(IpAddress::unpack(authFixtureRows('security_events')[0]['ip']))->toBe($address);
})->with([
    ['203.0.113.7', IpAddress::WIDTH_V4],
    ['2001:db8::1', IpAddress::WIDTH_V6],
]);

it('stores null for an absent address and refuses a value that is not one', function () {
    attemptHandler()->handle(RecordLoginAttempt::failed(
        email: 'owner@example.com',
        failureReason: RecordLoginAttempt::REASON_BAD_PASSWORD,
    ));

    // Absence is a third state: no address recorded is null, not an empty
    // string that would decode to nothing and not 0.0.0.0.
    expect(attemptRow()['ip'])->toBeNull()
        ->and(IpAddress::pack(null))->toBeNull()
        ->and(IpAddress::unpack(null))->toBeNull()
        // A value inet_pton refuses came from a caller that handed this
        // something which is not an address, and a login history quietly
        // missing one proxy's traffic is a hole found years later.
        ->and(fn () => IpAddress::pack('203.0.113.300'))
        ->toThrow(InvalidArgumentException::class, '[203.0.113.300] is not an IP address')
        ->and(fn () => IpAddress::pack('::gg'))->toThrow(InvalidArgumentException::class)
        // And the reverse: three bytes never came from pack().
        ->and(fn () => IpAddress::unpack('abc'))
        ->toThrow(InvalidArgumentException::class, 'a packed address is 4 or 16 bytes, not 3');
});
