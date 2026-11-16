<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Pins;

use Flexipos\Access\Pins\HandlerPinVerifier;
use Flexipos\Access\Support\PinPolicy;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\PinVerifier;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Hashing\Hasher;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 3 - PinVerifier (AC-39, AC-23's PIN half, D-30, F-5)
|--------------------------------------------------------------------------
|
| The published question "who typed this PIN?", answered by delegating to
| VerifyPinHandler. Proved here: the right code and PIN return the employee
| id; a wrong PIN, an unknown code and a locked employee each return null and
| the three nulls are indistinguishable; and **the hazard the contract's
| docblock names**: a wrong PIN verified inside an outer transaction that
| rolls back loses its failure-counter increment, which is why the verifier is
| called before any transaction opens.
*/

uses(TestCase::class);

const HPV_CLOCK = '2026-10-20 09:00:00.000';

const HPV_GOOD = '7391';

const HPV_WRONG = '8264';

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt(HPV_CLOCK));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function hpvVerifier(): PinVerifier
{
    return app(PinVerifier::class);
}

function hpvEmployee(string $code = 'cashier'): string
{
    $id = AccessSeed::employee($code);

    /** @var Hasher $hasher */
    $hasher = app('hash');

    AccessSeed::connection()->table('employees')->where('id', $id)->update([
        'pin_hash' => $hasher->make(HPV_GOOD),
        'pin_set_at' => HPV_CLOCK,
    ]);

    return $id;
}

function hpvAttempts(string $employeeId): int
{
    return (int) AccessSeed::connection()->table('employees')->where('id', $employeeId)->value('pin_failed_attempts');
}

it('binds the contract to the delegating verifier', function () {
    expect(hpvVerifier())->toBeInstanceOf(HandlerPinVerifier::class);
});

it('returns the employee id for the right code and PIN', function () {
    $id = hpvEmployee();

    expect(hpvVerifier()->verify('cashier', HPV_GOOD))->toBe($id)
        ->and(hpvVerifier()->verify('cashier', HPV_GOOD, '203.0.113.7'))->toBe($id);
});

it('returns null for a wrong PIN, an unknown code and a locked employee, and the three are indistinguishable', function () {
    $id = hpvEmployee();
    $locked = hpvEmployee('locked');

    AccessSeed::connection()->table('employees')->where('id', $locked)->update(['pin_locked_until' => '2026-10-20 09:10:00.000']);

    $answers = [
        'wrong pin' => hpvVerifier()->verify('cashier', HPV_WRONG),
        'unknown code' => hpvVerifier()->verify('nobody', HPV_GOOD),
        // The right PIN, but the employee is locked.
        'locked' => hpvVerifier()->verify('locked', HPV_GOOD),
    ];

    expect($answers)->toBe(['wrong pin' => null, 'unknown code' => null, 'locked' => null])
        // A refusal is the null, never a falsy look-alike a caller could branch on.
        ->and($answers['wrong pin'])->toBe($answers['unknown code'])
        ->and($answers['unknown code'])->toBe($answers['locked'])
        // The wrong PIN did count against the employee: access's own counter ran.
        ->and(hpvAttempts($id))->toBe(1);
});

it('the hazard, pinned: a wrong PIN verified inside an outer transaction that rolls back loses its counter increment', function () {
    $id = hpvEmployee();
    $connection = AccessSeed::connection();

    // Outside any transaction the failure is counted and stays counted.
    expect(hpvVerifier()->verify('cashier', HPV_WRONG))->toBeNull()
        ->and(hpvAttempts($id))->toBe(1);

    // Inside a transaction that then rolls back (a self_approval_not_allowed
    // refusal, say), the handler's own transaction is only a savepoint of the
    // caller's, so the rollback takes the increment with it.
    $connection->beginTransaction();

    expect(hpvVerifier()->verify('cashier', HPV_WRONG))->toBeNull()
        ->and(hpvAttempts($id))->toBe(2);

    $connection->rollBack();

    // Still 1, not 2: the second failure left no trace. That is exactly why
    // the contract says to call it before any transaction opens.
    expect(hpvAttempts($id))->toBe(1);
});

it('locks the employee after the configured number of failures when called outside a transaction', function () {
    $id = hpvEmployee();
    $max = app(PinPolicy::class)->lockoutAfter();

    for ($attempt = 0; $attempt < $max; $attempt++) {
        hpvVerifier()->verify('cashier', HPV_WRONG);
    }

    // Locked now: even the right PIN is refused.
    expect(hpvVerifier()->verify('cashier', HPV_GOOD))->toBeNull()
        ->and(AccessSeed::connection()->table('employees')->where('id', $id)->value('pin_locked_until'))->not->toBeNull();
});
