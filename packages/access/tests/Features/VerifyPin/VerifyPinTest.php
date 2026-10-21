<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\VerifyPin;

use Flexipos\Access\Features\SetPin\SetPin;
use Flexipos\Access\Features\SetPin\SetPinHandler;
use Flexipos\Access\Features\VerifyPin\VerifyPin;
use Flexipos\Access\Features\VerifyPin\VerifyPinHandler;
use Flexipos\Access\Features\VerifyPin\VerifyPinResult;
use Flexipos\Access\Support\AccessLogWriter;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\PinPolicy;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Access\Tests\Support\CountingHasher;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 18 - verifying a PIN, and the lockout (AC-49 to AC-51, AC-69)
|--------------------------------------------------------------------------
|
| **One refusal value, five causes, and the test is built around telling
| those two facts apart.** Every case below asserts that the caller's result
| is `==` equal to every other refusal - so no field survives for a till to
| branch on - and then reads the cause out of `access_log.detail.reason`,
| which is the only place it exists. That pair is the sibling-guard remedy
| T-009 D-12 required of `login_history.failure_reason`, applied here.
|
| **The hasher count is the criterion, not a performance note.** AC-50 says
| the hasher runs exactly once in every case, including the ones with
| nothing to compare against, and names its own negative control: a double
| that counts zero for the unknown-code case must turn the criterion red.
| That control is run here against the SHIPPED handler - the double is blind
| to the dummy digest, which is exactly what a handler skipping the dummy
| check would produce - by running the assertion itself twice: once on the
| honest double (green) and once on the blind one, inside an expectation
| that it throws.
|
| **Exactly one `access_log` row, counted exactly.** "At least one" is
| satisfied by a retry, a second writer and a double-registered listener,
| and an operator reading the log cannot tell any of them from two real
| attempts.
|
| **The lockout boundary is asserted on both sides in one dataset** (AC-51):
| at `pin_locked_until` minus one second the correct PIN is refused, at
| exactly `pin_locked_until` it is accepted. One side alone is also what a
| broken clock produces.
|
| Nothing here is a route: AC-49's control is the printed route list, and it
| is published rather than described, because an empty list would pass a
| test that only asserted absence.
|
*/

uses(TestCase::class);

const VERIFY_PIN_CLOCK = '2026-10-20 09:00:00.000';

const VERIFY_PIN_GOOD = '7391';

const VERIFY_PIN_WRONG = '8264';

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt(VERIFY_PIN_CLOCK));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Verify a PIN through the container-built handler.
 */
function verifyPin(string $code, string $pin, ?string $ip = null): VerifyPinResult
{
    /** @var VerifyPinHandler $handler */
    $handler = app(VerifyPinHandler::class);

    return $handler->handle(new VerifyPin($code, $pin, $ip));
}

/**
 * An acting employee holding a real PIN digest.
 *
 * Seeded through the facade, never through the injected contract, so a
 * counting double installed over the latter does not see the seeding - the
 * digests it counts checks against are real Argon2id ones.
 */
function employeeWithPin(string $code = 'cashier', string $pin = VERIFY_PIN_GOOD): string
{
    $id = AccessSeed::employee($code);

    /** @var Hasher $hasher */
    $hasher = app('hash');

    AccessSeed::connection()->table('employees')->where('id', $id)->update([
        'pin_hash' => $hasher->make($pin),
        'pin_set_at' => VERIFY_PIN_CLOCK,
    ]);

    return $id;
}

/**
 * Every `access_log` row, as arrays.
 *
 * @return list<array<string, mixed>>
 */
function accessLogRows(): array
{
    return AccessSeed::connection()->table('access_log')
        ->orderBy('id')
        ->get()
        ->map(static fn ($row): array => (array) $row)
        ->all();
}

/**
 * The instant a lock applied at the fixture clock expires.
 *
 * Computed from the configured figure rather than written out, so moving
 * `access.pin.lockout_minutes` moves the expectation with it.
 */
function lockExpiryFromNow(): string
{
    return RunnerClock::fixedAt(VERIFY_PIN_CLOCK)
        ->timestampInMinutes(app(PinPolicy::class)->lockoutMinutes());
}

/**
 * One employee's PIN counters.
 *
 * @return array{attempts: int, locked_until: ?string}
 */
function pinCounters(string $employeeId): array
{
    $row = (array) AccessSeed::connection()->table('employees')->where('id', $employeeId)->first();

    return [
        'attempts' => (int) $row['pin_failed_attempts'],
        'locked_until' => $row['pin_locked_until'] === null ? null : (string) $row['pin_locked_until'],
    ];
}

it('verifies a correct PIN, clears the counter and writes exactly one pin_login row (AC-49, AC-69)', function () {
    $employeeId = employeeWithPin();

    AccessSeed::connection()->table('employees')->where('id', $employeeId)
        ->update(['pin_failed_attempts' => 3]);

    $versionBefore = AccessSeed::permVersion($employeeId);

    $result = verifyPin('cashier', VERIFY_PIN_GOOD, '203.0.113.7');

    $rows = accessLogRows();

    expect($result->isVerified())->toBeTrue()
        ->and($result->employeeId)->toBe($employeeId)
        ->and($rows)->toHaveCount(1)
        ->and((string) $rows[0]['type'])->toBe(AccessLogWriter::TYPE_PIN_LOGIN)
        ->and((string) $rows[0]['employee_id'])->toBe($employeeId)
        ->and(AccessLogWriter::unpackIp($rows[0]['ip'] === null ? null : (string) $rows[0]['ip']))->toBe('203.0.113.7')
        ->and((string) $rows[0]['created_at'])->toBe(VERIFY_PIN_CLOCK)
        // AC-48 at this writer: the attempt is recorded, the credential is not.
        ->and((string) json_encode($rows[0]))->not->toContain(VERIFY_PIN_GOOD)
        ->and(pinCounters($employeeId)['attempts'])->toBe(0)
        // AC-52's not-bumped list, and AC-51's "a lock does not change status".
        ->and(AccessSeed::permVersion($employeeId))->toBe($versionBefore);
});

it('round-trips an IPv6 address through the packed column unchanged (AC-69)', function () {
    employeeWithPin();

    verifyPin('cashier', VERIFY_PIN_GOOD, '2001:db8::1');

    $rows = accessLogRows();

    // Read into PHP and unpacked, never measured with SQL: SQLite's
    // length() truncates a packed address at its first NUL byte.
    expect($rows)->toHaveCount(1)
        ->and(AccessLogWriter::unpackIp($rows[0]['ip'] === null ? null : (string) $rows[0]['ip']))->toBe('2001:db8::1');
});

it('answers one refusal value for every cause, with the cause only in the log (AC-50)', function (string $cause, string $expectedReason, bool $employeeNamed) {
    $employeeId = employeeWithPin();
    $code = 'cashier';
    $pin = VERIFY_PIN_GOOD;

    match ($cause) {
        'unknown code' => $code = 'nobody',
        'wrong pin' => $pin = VERIFY_PIN_WRONG,
        'no pin' => AccessSeed::connection()->table('employees')->where('id', $employeeId)
            ->update(['pin_hash' => null]),
        'locked' => AccessSeed::connection()->table('employees')->where('id', $employeeId)
            ->update(['pin_locked_until' => '2026-10-20 09:10:00.000']),
        'not acting' => AccessSeed::connection()->table('employees')->where('id', $employeeId)
            ->update(['status' => EmployeeStatus::SUSPENDED]),
        'soft deleted' => AccessSeed::connection()->table('employees')->where('id', $employeeId)
            ->update(['deleted_at' => VERIFY_PIN_CLOCK]),
        default => null,
    };

    $counting = CountingHasher::install();

    $result = verifyPin($code, $pin, '203.0.113.7');

    // The one refusal: no cause, and `==` equal to any other refusal, so a
    // caller has nothing to branch on even carelessly.
    expect($result->isVerified())->toBeFalse()
        ->and($result->employeeId)->toBeNull()
        ->and($result)->toEqual(VerifyPinResult::refused());

    $rows = accessLogRows();

    expect($rows)->toHaveCount(1)
        ->and((string) $rows[0]['type'])->toBe(AccessLogWriter::TYPE_PIN_FAILED)
        ->and(json_decode((string) $rows[0]['detail'], true))->toBe(['reason' => $expectedReason])
        ->and($expectedReason)->toBeIn(VerifyPinHandler::REASONS)
        ->and((string) json_encode($rows[0]))->not->toContain($pin)
        ->and($rows[0]['employee_id'] === null ? null : (string) $rows[0]['employee_id'])
        ->toBe($employeeNamed ? $employeeId : null)
        // The criterion: once, in every case, including the two with nothing
        // stored to compare against.
        ->and($counting->count())->toBe(1);
})->with([
    'unknown code' => ['unknown code', VerifyPinHandler::REASON_UNKNOWN_EMPLOYEE, false],
    'wrong pin' => ['wrong pin', VerifyPinHandler::REASON_BAD_PIN, true],
    'no pin' => ['no pin', VerifyPinHandler::REASON_NO_PIN, true],
    'locked' => ['locked', VerifyPinHandler::REASON_LOCKED, true],
    'not acting' => ['not acting', VerifyPinHandler::REASON_NOT_ACTING, true],
    'soft deleted' => ['soft deleted', VerifyPinHandler::REASON_UNKNOWN_EMPLOYEE, false],
]);

it('does the hashing work for a code that matches nobody, and a blind double proves the assertion bites (AC-50)', function () {
    employeeWithPin();

    // The assertion itself, so it can be run twice: once where it must hold
    // and once where it must fail.
    $assertOneCheckForAnUnknownCode = function (CountingHasher $counting): void {
        verifyPin('nobody', VERIFY_PIN_GOOD);

        expect($counting->count())->toBe(1)
            ->and($counting->checked)->toBe([VerifyPinHandler::DUMMY_PIN_HASH]);
    };

    // Green: the shipped handler checks the fixed dummy digest.
    $assertOneCheckForAnUnknownCode(CountingHasher::install());

    // The named negative control. The handler is untouched; the double
    // simply does not count the dummy digest, which is exactly what a
    // handler that skipped the check would show. If this expectation does
    // not throw, the count above was proving nothing.
    expect(fn () => $assertOneCheckForAnUnknownCode(
        CountingHasher::install(VerifyPinHandler::DUMMY_PIN_HASH)
    ))->toThrow(ExpectationFailedException::class);
});

it('succeeds for the same employee in the same run, so the refusals are compared against a success (AC-50)', function () {
    employeeWithPin();

    $counting = CountingHasher::install();

    $refused = verifyPin('cashier', VERIFY_PIN_WRONG);
    $verified = verifyPin('cashier', VERIFY_PIN_GOOD);

    expect($refused->isVerified())->toBeFalse()
        ->and($verified->isVerified())->toBeTrue()
        ->and($counting->count())->toBe(2)
        ->and(accessLogRows())->toHaveCount(2);
});

it('locks on the fifth consecutive wrong PIN and not on the fourth (AC-51)', function () {
    $employeeId = employeeWithPin();
    $other = employeeWithPin('second');

    // Derived, never typed: the threshold has one home (`config/access.php`
    // through `PinPolicy`), and a literal here would be its second.
    $threshold = app(PinPolicy::class)->lockoutAfter();

    for ($attempt = 1; $attempt < $threshold; $attempt++) {
        verifyPin('cashier', VERIFY_PIN_WRONG);

        expect(pinCounters($employeeId))->toBe(['attempts' => $attempt, 'locked_until' => null]);
    }

    // The fifth CORRECT PIN succeeds and resets the counter: the negative
    // control for the lock, because four failures that silently locked
    // would look identical from the log alone.
    expect(verifyPin('cashier', VERIFY_PIN_GOOD)->isVerified())->toBeTrue()
        ->and(pinCounters($employeeId))->toBe(['attempts' => 0, 'locked_until' => null]);

    for ($attempt = 1; $attempt <= $threshold; $attempt++) {
        verifyPin('cashier', VERIFY_PIN_WRONG);
    }

    // The counter resets AT the lock (D-17), so a stale five cannot lock the
    // employee again on their first wrong PIN after it expires.
    expect(pinCounters($employeeId))->toBe([
        'attempts' => 0,
        'locked_until' => lockExpiryFromNow(),
    ])
        // A different employee is unaffected by any of it.
        ->and(pinCounters($other))->toBe(['attempts' => 0, 'locked_until' => null]);
});

it('refuses the correct PIN one second before the lock expires and accepts it at the instant it does (AC-51)', function (string $at, bool $accepted) {
    $employeeId = employeeWithPin();

    AccessSeed::connection()->table('employees')->where('id', $employeeId)->update([
        'pin_locked_until' => '2026-10-20 09:15:00.000',
    ]);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt($at));

    $result = verifyPin('cashier', VERIFY_PIN_GOOD);

    expect($result->isVerified())->toBe($accepted);

    if (! $accepted) {
        // While locked: refused with `locked`, the counter stays 0 and the
        // lock is not extended.
        expect(json_decode((string) accessLogRows()[0]['detail'], true))
            ->toBe(['reason' => VerifyPinHandler::REASON_LOCKED])
            ->and(pinCounters($employeeId))->toBe([
                'attempts' => 0,
                'locked_until' => '2026-10-20 09:15:00.000',
            ]);
    }
})->with([
    'one second before' => ['2026-10-20 09:14:59.000', false],
    'at the instant' => ['2026-10-20 09:15:00.000', true],
    'after' => ['2026-10-20 09:15:00.001', true],
]);

it('starts the count afresh after a lock expires, so four wrong PINs do not lock (AC-51)', function () {
    $employeeId = employeeWithPin();

    AccessSeed::connection()->table('employees')->where('id', $employeeId)->update([
        'pin_locked_until' => '2026-10-20 09:15:00.000',
    ]);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-20 09:20:00.000'));

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        verifyPin('cashier', VERIFY_PIN_WRONG);
    }

    expect(pinCounters($employeeId))->toBe([
        'attempts' => 4,
        // The expired lock is left where it was: it is in the past, so it
        // locks nobody, and rewriting it would be a second place that
        // decides what "locked" means.
        'locked_until' => '2026-10-20 09:15:00.000',
    ]);

    expect(verifyPin('cashier', VERIFY_PIN_GOOD)->isVerified())->toBeTrue();
});

it('clears a lock when a new PIN is set, and the new PIN verifies at once (AC-47, AC-51)', function () {
    $employeeId = employeeWithPin();

    AccessSeed::connection()->table('employees')->where('id', $employeeId)->update([
        'pin_failed_attempts' => 0,
        'pin_locked_until' => '2026-10-20 09:15:00.000',
    ]);

    expect(verifyPin('cashier', VERIFY_PIN_GOOD)->isVerified())->toBeFalse();

    /** @var SetPinHandler $setter */
    $setter = app(SetPinHandler::class);
    $setter->handle(new SetPin($employeeId, '5826', null));

    expect(pinCounters($employeeId))->toBe(['attempts' => 0, 'locked_until' => null])
        ->and(verifyPin('cashier', '5826')->isVerified())->toBeTrue();
});

it('reaches the verify operation from no route at all, over the routes it printed (AC-49)', function () {
    $examined = [];
    $reaching = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $action = $route->getActionName();
        $examined[] = implode('|', $route->methods()).' '.$route->uri().' -> '.$action;

        $class = str_contains($action, '@') ? strstr($action, '@', true) : $action;

        if (! is_string($class) || ! class_exists($class)) {
            continue;
        }

        $file = (new \ReflectionClass($class))->getFileName();

        if ($file === false || ! is_file($file)) {
            continue;
        }

        if (str_contains((string) file_get_contents($file), 'VerifyPin')) {
            $reaching[] = $examined[count($examined) - 1];
        }
    }

    // The domain is published and asserted non-empty: "no route reaches the
    // verify operation" is also what a test looking at an empty router
    // reports, and that is the shape D-16's control exists to exclude.
    expect($examined)->not->toBe([], 'routes examined: '.implode(' / ', $examined))
        ->and($reaching)->toBe([], 'routes reaching VerifyPin: '.implode(' / ', $reaching));
});

it('refuses an access log type outside D-21s three, and writes the three (D-21, AC-69)', function () {
    /** @var AccessLogWriter $writer */
    $writer = app(AccessLogWriter::class);

    $connection = AccessSeed::connection();
    $employeeId = AccessSeed::employee('cashier');

    expect(fn () => $writer->write($connection, 'terminal_login', $employeeId, null, null, [], null))
        ->toThrow(InvalidArgumentException::class)
        ->and(AccessLogWriter::TYPES)->toBe(['pin_login', 'pin_failed', 'permission_denied'])
        ->and(accessLogRows())->toBe([]);

    // The liveness half: a refusal that refuses everything is also a refusal.
    foreach (AccessLogWriter::TYPES as $type) {
        $writer->write($connection, $type, $employeeId, null, null, ['reason' => 'fixture'], null);
    }

    expect(accessLogRows())->toHaveCount(count(AccessLogWriter::TYPES));
});

it('reads the employee under a row lock, so simultaneous guesses take turns (AC-51, review W-1)', function () {
    employeeWithPin();

    $connection = AccessSeed::connection();

    // The stand-in compiles no lock clause, so the statements are compiled
    // with MySQL's grammar and recorded under pretend(): nothing executes,
    // the read finds nobody, and the handler takes the unknown-code path.
    // What is asserted is the read it would send the engine.
    $standIn = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new MySqlGrammar($connection));

    try {
        $log = $connection->pretend(fn () => verifyPin('cashier', VERIFY_PIN_WRONG));
    } finally {
        $connection->setQueryGrammar($standIn);
    }

    $reads = array_values(array_filter(
        array_map(static fn (array $entry): string => (string) $entry['query'], $log),
        static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from `employees`')
    ));

    fwrite(STDOUT, "\nVerifyPin employee reads (MySQL grammar):\n".implode("\n", $reads)."\n");

    expect($reads)->toHaveCount(1)
        ->and($reads[0])->toContain("`code` = 'cashier'")
        ->and($reads[0])->toEndWith(' for update');

    // The liveness control: on the stand-in's own grammar the same handler
    // still verifies, so the lock changed what MySQL is sent and nothing here.
    expect(verifyPin('cashier', VERIFY_PIN_GOOD)->isVerified())->toBeTrue();
});
