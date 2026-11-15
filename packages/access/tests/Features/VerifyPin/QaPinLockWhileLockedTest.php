<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\VerifyPin;

use Flexipos\Access\Features\VerifyPin\VerifyPin;
use Flexipos\Access\Features\VerifyPin\VerifyPinHandler;
use Flexipos\Access\Support\PinPolicy;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Hashing\Hasher;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 QA - wrong PINs during a lock (AC-51, D-17)
|--------------------------------------------------------------------------
|
| AC-51: "while locked the correct PIN is refused with reason `locked`, the
| counter stays 0 and `pin_locked_until` is **not extended**". D-17 says the
| same of FAILURES while locked: "failures while locked are not counted and
| do not extend the lock".
|
| The dev suite asserts the first with the CORRECT PIN only. A mutant that
| counts a wrong PIN submitted during the lock - and so rewrites
| `pin_locked_until` to now plus 15 minutes on the fifth one - left all 291
| package tests green, because a correct PIN never reaches the counting
| branch. That mutant is the attack the rule exists to stop: anyone who can
| reach the till can hold a cashier's PIN locked indefinitely by guessing
| during the lock, and the lock is the thing meant to slow guessing.
|
| **The discriminating input is a WRONG PIN during the lock**, repeated past
| the threshold, because only a wrong PIN can reach `countFailure`. The
| liveness control is the same loop run after the lock has expired, where
| the very same submissions DO count and DO lock - so "the counter did not
| move" is a statement about the lock and not about a loop that never counted.
|
*/

uses(TestCase::class);

const QA_LOCK_CLOCK = '2026-10-20 09:00:00.000';

const QA_LOCK_GOOD = '7391';

const QA_LOCK_WRONG = '8264';

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt(QA_LOCK_CLOCK));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function qaLockVerify(string $pin): bool
{
    return app(VerifyPinHandler::class)->handle(new VerifyPin('cashier', $pin, null))->isVerified();
}

/**
 * @return array{attempts: int, locked_until: ?string}
 */
function qaLockCounters(string $employeeId): array
{
    $row = (array) AccessSeed::connection()->table('employees')->where('id', $employeeId)->first();

    return [
        'attempts' => (int) $row['pin_failed_attempts'],
        'locked_until' => $row['pin_locked_until'] === null ? null : (string) $row['pin_locked_until'],
    ];
}

function qaLockEmployee(): string
{
    $id = AccessSeed::employee('cashier');

    /** @var Hasher $hasher */
    $hasher = app('hash');

    AccessSeed::connection()->table('employees')->where('id', $id)->update([
        'pin_hash' => $hasher->make(QA_LOCK_GOOD),
        'pin_set_at' => QA_LOCK_CLOCK,
    ]);

    return $id;
}

it('neither counts nor extends on wrong PINs submitted during a lock, and does both once it has expired (AC-51, D-17)', function () {
    $employeeId = qaLockEmployee();
    $threshold = app(PinPolicy::class)->lockoutAfter();

    // Lock the employee through the real path.
    for ($attempt = 1; $attempt <= $threshold; $attempt++) {
        qaLockVerify(QA_LOCK_WRONG);
    }

    $locked = qaLockCounters($employeeId);

    expect($locked['attempts'])->toBe(0)
        ->and($locked['locked_until'])->not->toBeNull();

    // Time moves forward INSIDE the lock, so a rewritten expiry would differ
    // from the stored one (a clock frozen at the lock instant would let an
    // extension write the same value back and pass).
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-20 09:10:00.000'));

    // More wrong PINs than the threshold, during the lock.
    for ($attempt = 1; $attempt <= $threshold * 2; $attempt++) {
        expect(qaLockVerify(QA_LOCK_WRONG))->toBeFalse();
    }

    expect(qaLockCounters($employeeId))->toBe($locked, 'wrong PINs during a lock must not count and must not extend it');

    // The correct PIN is still refused, and still changes nothing.
    expect(qaLockVerify(QA_LOCK_GOOD))->toBeFalse()
        ->and(qaLockCounters($employeeId))->toBe($locked);

    // The log says `locked` for every one of them: the cause the operator
    // reads is the lock, not a run of bad guesses.
    $reasons = AccessSeed::connection()->table('access_log')
        ->where('type', 'pin_failed')
        ->orderBy('id')
        ->pluck('detail')
        ->map(static fn ($detail): string => (string) (json_decode((string) $detail, true)['reason'] ?? ''))
        ->all();

    expect(array_slice($reasons, $threshold))->each->toBe('locked');

    // Liveness: after the lock expires the same submissions DO count, and
    // the fifth DOES lock again from the new instant.
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-20 09:30:00.000'));

    qaLockVerify(QA_LOCK_WRONG);

    expect(qaLockCounters($employeeId)['attempts'])->toBe(1);

    for ($attempt = 2; $attempt <= $threshold; $attempt++) {
        qaLockVerify(QA_LOCK_WRONG);
    }

    $relocked = qaLockCounters($employeeId);

    expect($relocked['attempts'])->toBe(0)
        ->and($relocked['locked_until'])->not->toBe($locked['locked_until']);
});
