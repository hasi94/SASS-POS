<?php

use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Features\Login\Login;
use Flexipos\Auth\Features\Login\LoginHandler;
use Flexipos\Auth\Features\Login\LoginResult;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
use Flexipos\Auth\Support\GenericRefusal;
use Flexipos\Auth\Support\IpAddress;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 9: Login - refusal, the dummy hash, and the lockout
|--------------------------------------------------------------------------
|
| AC-6, AC-7, AC-8, AC-16 to AC-21, and AC-22 by reading.
|
| **Every test in this file asserts `login_history.failure_reason`, and that
| is not decoration.** D-12 makes an unknown email, a wrong password, a live
| lock, an account that is not `active` and a soft-deleted account produce one
| byte-identical refusal; five mechanisms, one observable. A test that
| asserted the refusal alone would pass while the wrong mechanism fired - plan
| section 7, mask shape 3 - so each case here pins the reason **and** the
| state its cause should have moved (`failed_attempts`, `locked_until`), which
| between them no other mechanism can produce.
|
| **AC-16 and AC-17 are each other's control** and are kept as two tests on
| purpose: four failures that must *not* lock is the only thing that
| distinguishes a threshold of five from a threshold of one, and collapsing
| them into one dataset would hide which half failed.
|
| **AC-22 has no test here, deliberately.** The criterion is about N
| simultaneous attempts against one account, one SQLite connection cannot
| produce them, and a green sequential test would look like evidence while
| being none. The atomic `UPDATE ... SET failed_attempts = failed_attempts +
| 1` is in `LoginHandler::countFailure()` for review, and step 14 lists AC-22
| as NOT VERIFIED - engine hold.
|
| The master is the suite's in-memory SQLite stand-in, asserted to be SQLite
| before anything migrates. Nothing here reaches the engine at
| 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../Support/auth-fixture.php';

uses(TestCase::class);

const REFUSAL_PASSWORD = 'correct-horse-battery-staple';

const REFUSAL_CLOCK = '2026-10-15 09:00:00.000';

beforeEach(function () {
    authFixtureClockAt(REFUSAL_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey('refusal-key-1');
});

function refusalCommand(string $email, string $password = REFUSAL_PASSWORD): Login
{
    return new Login(
        email: $email,
        password: $password,
        ip: '203.0.113.7',
        userAgent: 'FlexiPOS/1.0',
    );
}

/**
 * Attempt a login and hand back what came out: either the result or the
 * refusal.
 */
function refusalAttempt(string $email, string $password = REFUSAL_PASSWORD): LoginResult|CredentialRefused
{
    try {
        return authFixtureLoginHandler()->handle(refusalCommand($email, $password));
    } catch (CredentialRefused $refused) {
        return $refused;
    }
}

/**
 * The failure reason of the newest `login_history` row.
 */
function refusalLastReason(): ?string
{
    $rows = authFixtureRows('login_history');

    expect($rows)->not->toBe([]);

    $last = $rows[count($rows) - 1];

    return is_string($last['failure_reason']) ? $last['failure_reason'] : null;
}

/**
 * Everything a client could possibly tell two refusals apart by: the status,
 * the body as bytes, the exception's class and its message.
 *
 * @return list<string>
 */
function refusalFingerprint(CredentialRefused $refused): array
{
    return [
        (string) GenericRefusal::STATUS,
        GenericRefusal::json(),
        $refused::class,
        $refused->getMessage(),
    ];
}

/*
|--------------------------------------------------------------------------
| AC-6: one refusal, byte for byte, and a success to compare it against
|--------------------------------------------------------------------------
*/

it('refuses a wrong password and an unknown address identically, and lets the right one through', function () {
    authFixtureUser('owner@example.com');

    $wrongPassword = refusalAttempt('owner@example.com', 'not-the-password');
    $unknownAddress = refusalAttempt('nobody@example.com');

    expect($wrongPassword)->toBeInstanceOf(CredentialRefused::class)
        ->and($unknownAddress)->toBeInstanceOf(CredentialRefused::class);

    // Byte-identical, which is the criterion - not merely "both refused".
    expect(refusalFingerprint($wrongPassword))
        ->toBe(refusalFingerprint($unknownAddress));

    // AC-6's own positive control, in this test: the same account, the right
    // password, in the same run - so the comparison above is between a
    // refusal and a success and not between two refusals of nothing.
    expect(refusalAttempt('owner@example.com'))->toBeInstanceOf(LoginResult::class);

    // And the two causes are distinguishable in the one place they are meant
    // to be.
    expect(array_column(authFixtureRows('login_history'), 'failure_reason'))
        ->toBe(['bad_password', 'unknown_user', null]);
});

it('builds its body from nothing, so no cause can reach it', function () {
    $constructor = (new ReflectionClass(GenericRefusal::class))->getConstructor();

    expect($constructor)->toBeNull()
        ->and((new ReflectionMethod(GenericRefusal::class, 'body'))->getNumberOfParameters())->toBe(0)
        ->and(GenericRefusal::json())->toBe(GenericRefusal::json())
        ->and(GenericRefusal::STATUS)->toBe(401);
});

/*
|--------------------------------------------------------------------------
| AC-7: every account state that refuses, each with its own control
|--------------------------------------------------------------------------
*/

it('refuses an account that is not active, and admits the same account once it is', function (string $status) {
    $userId = authFixtureUser('owner@example.com', REFUSAL_PASSWORD, ['status' => $status]);

    expect(refusalAttempt('owner@example.com'))->toBeInstanceOf(CredentialRefused::class)
        ->and(refusalLastReason())->toBe(RecordLoginAttempt::REASON_NOT_ACTIVE)
        ->and(authFixtureRows('refresh_tokens'))->toBe([])
        ->and(authFixtureUserRow($userId)['last_login_at'])->toBeNull();

    // The control AC-7 requires for each state separately: flip this one back
    // and the same credentials work, so the refusal was caused by the state
    // under test and not by the fixture.
    DB::connection('master')->table('users')->where('id', $userId)->update(['status' => 'active']);

    expect(refusalAttempt('owner@example.com'))->toBeInstanceOf(LoginResult::class);
})->with([
    'invited' => 'invited',
    'disabled' => 'disabled',
    'locked' => 'locked',
]);

it('treats a soft-deleted account as one that never existed', function () {
    $userId = authFixtureUser('owner@example.com', REFUSAL_PASSWORD, ['deleted_at' => REFUSAL_CLOCK]);

    expect(refusalAttempt('owner@example.com'))->toBeInstanceOf(CredentialRefused::class)
        // Absent, not inactive: the lookup filters `deleted_at`, so the row
        // records `unknown_user` with no `user_id` - the spec's rule that a
        // soft-deleted row counts as absent everywhere.
        ->and(refusalLastReason())->toBe(RecordLoginAttempt::REASON_UNKNOWN_USER)
        ->and(authFixtureRows('login_history')[0]['user_id'])->toBeNull()
        ->and(authFixtureRows('refresh_tokens'))->toBe([]);

    DB::connection('master')->table('users')->where('id', $userId)->update(['deleted_at' => null]);

    expect(refusalAttempt('owner@example.com'))->toBeInstanceOf(LoginResult::class);
});

/*
|--------------------------------------------------------------------------
| AC-8: the same hasher work for an address that matches nobody
|--------------------------------------------------------------------------
*/

/**
 * Run one attempt against a counting hasher and report what it was asked to
 * verify against.
 *
 * @param  string|null  $blindTo  a digest the double refuses to count - the
 *                                negative control
 * @return list<string>
 */
function refusalHasherWork(string $email, string $password, ?string $blindTo = null): array
{
    $hasher = authFixtureCountingHasher($blindTo);

    refusalAttempt($email, $password);

    return $hasher->checked;
}

/**
 * AC-8's assertion, as one function so the negative control can be the same
 * assertion rather than a similar one.
 *
 * @param  list<string>  $unknownAddress
 * @param  list<string>  $wrongPassword
 */
function refusalAssertEqualWork(array $unknownAddress, array $wrongPassword, string $storedHash): void
{
    expect($unknownAddress)->toBe([LoginHandler::DUMMY_PASSWORD_HASH])
        ->and($wrongPassword)->toBe([$storedHash])
        ->and(count($unknownAddress))->toBe(count($wrongPassword));
}

it('hashes exactly once against a fixed dummy digest for an address that matches nobody', function () {
    $userId = authFixtureUser('owner@example.com');
    $storedHash = (string) authFixtureUserRow($userId)['password_hash'];

    refusalAssertEqualWork(
        refusalHasherWork('nobody@example.com', REFUSAL_PASSWORD),
        refusalHasherWork('owner@example.com', 'not-the-password'),
        $storedHash,
    );

    // The dummy is a real Argon2id digest, which is what keeps the branch a
    // refusal rather than a 500: `hashing.verify` is on and the driver throws
    // on anything else.
    expect(password_get_info(LoginHandler::DUMMY_PASSWORD_HASH)['algoName'])->toBe('argon2id')
        ->and(password_verify(REFUSAL_PASSWORD, LoginHandler::DUMMY_PASSWORD_HASH))->toBeFalse();
});

it('is red when the hasher double counts zero for the unknown address', function () {
    $userId = authFixtureUser('owner@example.com');
    $storedHash = (string) authFixtureUserRow($userId)['password_hash'];

    // The negative control the spec names: a double blind to the dummy
    // digest sees no work on the unknown-address path, which is exactly what
    // a handler that skipped the dummy verification would produce. The
    // *same* assertion must refuse it.
    expect(fn () => refusalAssertEqualWork(
        refusalHasherWork('nobody@example.com', REFUSAL_PASSWORD, LoginHandler::DUMMY_PASSWORD_HASH),
        refusalHasherWork('owner@example.com', 'not-the-password'),
        $storedHash,
    ))->toThrow(ExpectationFailedException::class);
});

/*
|--------------------------------------------------------------------------
| AC-16 and AC-17: four failures and five, each the other's control
|--------------------------------------------------------------------------
*/

it('does not lock on four wrong passwords, and the fifth right one resets the count', function () {
    $userId = authFixtureUser('owner@example.com');

    foreach (range(1, 4) as $attempt) {
        expect(refusalAttempt('owner@example.com', 'not-the-password'))->toBeInstanceOf(CredentialRefused::class)
            ->and((int) authFixtureUserRow($userId)['failed_attempts'])->toBe($attempt);
    }

    $row = authFixtureUserRow($userId);

    expect($row['locked_until'])->toBeNull()
        ->and((int) $row['failed_attempts'])->toBe(4)
        ->and(authFixtureRows('security_events'))->toBe([]);

    // The fifth attempt, with the right password: it succeeds *because* the
    // account was never locked, and the counter goes back to zero (AC-3).
    expect(refusalAttempt('owner@example.com'))->toBeInstanceOf(LoginResult::class)
        ->and((int) authFixtureUserRow($userId)['failed_attempts'])->toBe(0);
});

it('locks on the fifth wrong password, for fifteen minutes, without changing the status', function () {
    $userId = authFixtureUser('owner@example.com');

    foreach (range(1, 5) as $ignored) {
        expect(refusalAttempt('owner@example.com', 'not-the-password'))->toBeInstanceOf(CredentialRefused::class);
    }

    $row = authFixtureUserRow($userId);

    expect($row['locked_until'])->toBe('2026-10-15 09:15:00.000')
        // D-5: the counter resets as the lock is taken, so the lock is a
        // window and not a sliding one.
        ->and((int) $row['failed_attempts'])->toBe(0)
        // A lock is a timer, not a state.
        ->and($row['status'])->toBe('active');

    $events = authFixtureRows('security_events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe(RecordLoginAttempt::EVENT_LOCKOUT)
        ->and($events[0]['user_id'])->toBe($userId)
        ->and(IpAddress::unpack(is_string($events[0]['ip']) ? $events[0]['ip'] : null))->toBe('203.0.113.7');

    // AC-25: the attempt that locked the account is still a `bad_password`
    // attempt, and both rows exist.
    expect(array_column(authFixtureRows('login_history'), 'failure_reason'))
        ->toBe(array_fill(0, 5, 'bad_password'));
});

/*
|--------------------------------------------------------------------------
| AC-18, AC-19: what a live lock does, and exactly when it stops
|--------------------------------------------------------------------------
*/

it('refuses the right password during a lock, and does not extend it', function () {
    $userId = authFixtureUser('owner@example.com', REFUSAL_PASSWORD, [
        'failed_attempts' => 0,
        'locked_until' => '2026-10-15 09:15:00.000',
    ]);

    $refused = refusalAttempt('owner@example.com');

    $row = authFixtureUserRow($userId);

    expect($refused)->toBeInstanceOf(CredentialRefused::class)
        // Indistinguishable from a wrong password (AC-6) - and the one place
        // it is distinguishable says `locked`.
        ->and(refusalLastReason())->toBe(RecordLoginAttempt::REASON_LOCKED)
        ->and((int) $row['failed_attempts'])->toBe(0)
        // Not extended, not re-stamped: the same instant it held before.
        ->and($row['locked_until'])->toBe('2026-10-15 09:15:00.000')
        ->and(authFixtureRows('refresh_tokens'))->toBe([]);
});

it('refuses one second before the lock expires and accepts at the instant it does', function (string $now, bool $accepted) {
    // Both sides of the boundary in one dataset, as AC-19 requires: a `>`
    // written where `>=` was meant is invisible anywhere except at exactly T.
    authFixtureUser('owner@example.com', REFUSAL_PASSWORD, [
        'failed_attempts' => 0,
        'locked_until' => '2026-10-15 09:15:00.000',
    ]);

    authFixtureClockAt($now);

    $outcome = refusalAttempt('owner@example.com');

    expect($outcome)->toBeInstanceOf($accepted ? LoginResult::class : CredentialRefused::class);

    if (! $accepted) {
        expect(refusalLastReason())->toBe(RecordLoginAttempt::REASON_LOCKED);
    }
})->with([
    'T minus one second' => ['2026-10-15 09:14:59.000', false],
    'exactly T' => ['2026-10-15 09:15:00.000', true],
    'after T' => ['2026-10-15 09:20:00.000', true],
]);

/*
|--------------------------------------------------------------------------
| AC-20, AC-21: the count starts afresh, and nobody else is affected
|--------------------------------------------------------------------------
*/

it('counts afresh after a lock has expired', function () {
    $userId = authFixtureUser('owner@example.com', REFUSAL_PASSWORD, [
        // The state D-5 leaves behind: the lock taken, the counter zeroed.
        'failed_attempts' => 0,
        'locked_until' => '2026-10-15 09:15:00.000',
    ]);

    authFixtureClockAt('2026-10-15 09:16:00.000');

    foreach (range(1, 4) as $attempt) {
        refusalAttempt('owner@example.com', 'not-the-password');

        expect((int) authFixtureUserRow($userId)['failed_attempts'])->toBe($attempt)
            // Still the expired one, untouched: four is not five.
            ->and(authFixtureUserRow($userId)['locked_until'])->toBe('2026-10-15 09:15:00.000');
    }

    refusalAttempt('owner@example.com', 'not-the-password');

    expect(authFixtureUserRow($userId)['locked_until'])->toBe('2026-10-15 09:31:00.000')
        ->and((int) authFixtureUserRow($userId)['failed_attempts'])->toBe(0);
});

it('locks one account without touching another, and creates nothing for an unknown address', function () {
    $locked = authFixtureUser('locked@example.com');
    $other = authFixtureUser('other@example.com');

    foreach (range(1, 5) as $ignored) {
        refusalAttempt('locked@example.com', 'not-the-password');
    }

    expect(authFixtureUserRow($locked)['locked_until'])->not->toBeNull();

    // B is a different account and is unaffected.
    expect(refusalAttempt('other@example.com'))->toBeInstanceOf(LoginResult::class);

    // AC-21's second half: wrong passwords for an address that matches
    // nobody change no `users` row and create none.
    $before = authFixtureRows('users');

    foreach (range(1, 5) as $ignored) {
        refusalAttempt('nobody@example.com', 'not-the-password');
    }

    expect(authFixtureRows('users'))->toBe($before)
        ->and(array_slice(array_column(authFixtureRows('login_history'), 'failure_reason'), -5))
        ->toBe(array_fill(0, 5, 'unknown_user'));
});
