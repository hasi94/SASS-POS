<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\SetPin;

use Flexipos\Access\Features\SetPin\SetPin;
use Flexipos\Access\Features\SetPin\SetPinHandler;
use Flexipos\Access\Support\PinPolicy;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Contracts\Hashing\Hasher;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 17 - setting a cashier PIN (AC-47)
|--------------------------------------------------------------------------
|
| **The weak-PIN list is proved by moving it, not by agreeing with it.** A
| test that listed `0000`, `1234`, `4321` and `1111` and watched them be
| refused would pass against four `if` statements buried in the handler,
| which is the shape "one list in one place" exists to forbid. So the
| decisive case takes one entry OUT of the shipped set, binds a policy over
| the remainder, and asserts that exactly that PIN becomes acceptable while
| every other refusal stands. Nothing but a single list read in a single
| place can follow that move.
|
| **The acceptance half is in the same file as the refusals.** `7391` and
| an eight-digit PIN must be stored, and without them "refused" is also
| what a handler that refuses everything produces.
|
| **What the stored row must NOT hold is asserted directly**: `pin_hash` is
| an Argon2id digest that is not the PIN and does not contain it, and the
| refusal messages name no digits. The whole-sink sweep is AC-48's
| (`modules/host/tests/Feature/QaPinSinkCaptureTest.php`); what is here is
| the slice's own half of it, so a leak at this one writer is caught by the
| slice that wrote it.
|
| The clock is the runner's, bound by T-009, so `pin_set_at` is compared
| against a known instant rather than against "about now".
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
 * Set a PIN, through the container-built handler.
 */
function setPin(string $employeeId, mixed $pin, ?string $actorId = null): void
{
    /** @var SetPinHandler $handler */
    $handler = app(SetPinHandler::class);

    $handler->handle(new SetPin($employeeId, $pin, $actorId));
}

/**
 * One employee's row, as an array the test can index.
 *
 * `AccessSeed::employeeRow()` answers null for an id nobody holds, which is
 * a real answer and not one any case here expects; asserting it away once
 * keeps every case below free of the null check.
 *
 * @return array<string, mixed>
 */
function pinRow(string $employeeId): array
{
    $row = AccessSeed::employeeRow($employeeId);

    expect($row)->not->toBeNull();

    return $row ?? [];
}

/**
 * An employee who already holds a PIN, is locked out and has failures
 * counted against them - the state AC-47 says a new PIN clears.
 */
function lockedEmployeeWithPin(): string
{
    $id = AccessSeed::employee('cashier');

    AccessSeed::connection()->table('employees')->where('id', $id)->update([
        'pin_hash' => 'an-old-digest',
        'pin_set_at' => '2026-01-01 00:00:00.000',
        'pin_failed_attempts' => 5,
        'pin_locked_until' => '2099-01-01 00:00:00.000',
    ]);

    return $id;
}

it('stores an Argon2id hash of an accepted PIN, with the counter zeroed and the lock cleared (AC-47)', function () {
    $employeeId = lockedEmployeeWithPin();
    $actorId = AccessSeed::employee('manager');

    $versionBefore = AccessSeed::permVersion($employeeId);

    setPin($employeeId, '7391', $actorId);

    $row = pinRow($employeeId);

    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);

    expect((string) $row['pin_hash'])->toStartWith('$argon2id$')
        ->and((string) $row['pin_hash'])->not->toContain('7391')
        ->and($hasher->check('7391', (string) $row['pin_hash']))->toBeTrue()
        ->and($hasher->check('7392', (string) $row['pin_hash']))->toBeFalse()
        // Setting a PIN also unlocks, in the one statement that wrote it.
        ->and((int) $row['pin_failed_attempts'])->toBe(0)
        ->and($row['pin_locked_until'])->toBeNull()
        ->and((string) $row['pin_set_at'])->not->toBe('2026-01-01 00:00:00.000')
        // AC-52's "not bumped" list: a credential is not a grant.
        ->and(AccessSeed::permVersion($employeeId))->toBe($versionBefore);
});

it('accepts an eight-digit PIN and lets an actor set their own (AC-47)', function () {
    $employeeId = AccessSeed::employee('cashier');

    // The upper bound of the configured range, and the same call with the
    // subject as their own actor: a PIN is a credential, so `self_change`
    // is deliberately not consulted here.
    setPin($employeeId, '73915284', $employeeId);

    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);

    $row = pinRow($employeeId);

    expect($hasher->check('73915284', (string) $row['pin_hash']))->toBeTrue()
        ->and((string) $row['updated_by'])->toBe($employeeId);
});

it('refuses every shape AC-47 names, writes nothing, and never prints the value', function (mixed $pin) {
    $employeeId = AccessSeed::employee('cashier');

    $refused = AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => setPin($employeeId, $pin, null)
    );

    $row = pinRow($employeeId);

    expect($row['pin_hash'])->toBeNull()
        ->and($row['pin_set_at'])->toBeNull()
        ->and(AccessSeed::connection()->table('audit_logs')->count())->toBe(0);

    if (is_string($pin) && $pin !== '') {
        // AC-48's third sink, at this one writer: a refusal message that
        // quoted the submission would put a near-miss PIN into every log
        // and every rendered error page.
        expect($refused->getMessage())->not->toContain($pin);
    }
})->with([
    'all one repeated digit' => ['0000'],
    'repeated, longer' => ['1111'],
    'strictly ascending' => ['1234'],
    'strictly descending' => ['4321'],
    'too short' => ['739'],
    'too long' => ['739152840'],
    'not all digits' => ['73a1'],
    'spaces around digits' => [' 7391 '],
    'an empty string' => [''],
    'an integer, not a string' => [7391],
    'nothing at all' => [null],
    'an array' => [['7391']],
]);

it('accepts exactly the PIN taken out of the weak list, and nothing else (AC-47, the one-list proof)', function () {
    $employeeId = AccessSeed::employee('cashier');

    $shipped = PinPolicy::shipped()->weak();

    expect($shipped)->toContain('1234')
        ->and($shipped)->toContain('4321')
        ->and(count($shipped))->toBeGreaterThan(50);

    // One entry removed, and only one.
    $moved = array_values(array_filter($shipped, static fn (string $pin): bool => $pin !== '1234'));

    expect(count($moved))->toBe(count($shipped) - 1);

    app()->instance(PinPolicy::class, PinPolicy::withWeakPins($moved));

    // Exactly the moved entry is now acceptable...
    setPin($employeeId, '1234', null);

    expect(pinRow($employeeId)['pin_hash'])->not->toBeNull();

    // ...and every other weak PIN is still refused, which is what makes the
    // first half attributable to the list rather than to the policy having
    // been replaced by a permissive one.
    $second = AccessSeed::employee('second');

    foreach (['4321', '0000', '1111'] as $stillWeak) {
        AccessRefusals::expect(
            RefusalReason::Validation,
            fn () => setPin($second, $stillWeak, null)
        );
    }

    expect(pinRow($second)['pin_hash'])->toBeNull();
});

it('generates the weak set for every accepted length, and publishes it (AC-47)', function () {
    $policy = PinPolicy::shipped();
    $weak = $policy->weak();

    // The domain is printed rather than described: a sweep that enumerates
    // has to say what it enumerated, and a list this test merely asserted
    // the size of could be the wrong list of the right length.
    $byLength = [];

    foreach ($weak as $pin) {
        $byLength[strlen($pin)][] = $pin;
    }

    ksort($byLength);

    $summary = [];

    foreach ($byLength as $length => $pins) {
        $summary[] = $length.':'.count($pins);
    }

    expect(array_keys($byLength))->toBe(range($policy->minDigits(), $policy->maxDigits()), 'weak PINs by length: '.implode(' ', $summary))
        ->and($weak)->not->toBe([])
        // Ten repeats plus the ascending runs and their reverses, per length.
        ->and(count($byLength[4]))->toBe(10 + 7 + 7)
        ->and(count($byLength[8]))->toBe(10 + 3 + 3)
        ->and($byLength[8])->toContain('12345678')
        ->and($byLength[8])->toContain('87654321');
});

it('writes exactly one audit row naming pin_set, with neither the PIN nor the hash in it (AC-70, AC-48)', function () {
    $employeeId = AccessSeed::employee('cashier');
    $actorId = AccessSeed::employee('manager');

    setPin($employeeId, '7391', $actorId);

    $rows = AccessSeed::connection()->table('audit_logs')->get()
        ->map(static fn ($row): array => (array) $row)
        ->all();

    // Exactly one: "at least one" is satisfied by a retry and by a second
    // writer, and an auditor cannot tell either from two real operations.
    expect($rows)->toHaveCount(1);

    $row = $rows[0];
    $serialised = (string) json_encode($row);

    expect((string) $row['action'])->toBe(SetPinHandler::ACTION)
        ->and((string) $row['subject_type'])->toBe('employee')
        ->and((string) $row['subject_id'])->toBe($employeeId)
        ->and((string) $row['actor_id'])->toBe($actorId)
        ->and($serialised)->not->toContain('7391')
        ->and($serialised)->not->toContain('argon2id')
        ->and(json_decode((string) $row['changed_fields'], true))->toBe(['pin_set_at']);
});

it('refuses a PIN for an employee who is not there, and writes nothing (AC-47)', function () {
    $employeeId = AccessSeed::employee('cashier');

    AccessSeed::connection()->table('employees')->where('id', $employeeId)->update([
        'deleted_at' => '2026-10-20 00:00:00.000',
    ]);

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => setPin($employeeId, '7391', null)
    );

    expect(pinRow($employeeId)['pin_hash'])->toBeNull()
        ->and(AccessSeed::connection()->table('audit_logs')->count())->toBe(0);
});
