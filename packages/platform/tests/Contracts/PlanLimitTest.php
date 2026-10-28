<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Contracts;

use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Contracts\Platform\PlanLimit;
use Flexipos\Contracts\Platform\PlanLimitReached;

/*
|--------------------------------------------------------------------------
| T-016 step 1 - PlanLimit::assertRoomFor, the one comparison (AC-1, AC-3)
|--------------------------------------------------------------------------
*/

it('allows one below the limit, refuses at the limit, and refuses above it', function () {
    $limit = new PlanLimit(LimitKey::Employees, 5);

    $limit->assertRoomFor(4);

    expect(fn () => $limit->assertRoomFor(5))->toThrow(PlanLimitReached::class);
    expect(fn () => $limit->assertRoomFor(6))->toThrow(PlanLimitReached::class);
});

it('names the key and the limit on the exception it throws', function () {
    $limit = new PlanLimit(LimitKey::Branches, 5);
    $caught = null;

    try {
        $limit->assertRoomFor(5);
    } catch (PlanLimitReached $reached) {
        $caught = $reached;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->limitKey())->toBe(LimitKey::Branches)
        ->and($caught->limit())->toBe(5);
});

it('never refuses when the value is null, however large the count', function () {
    $limit = new PlanLimit(LimitKey::Terminals, null);

    $limit->assertRoomFor(10_000);

    expect(true)->toBeTrue();
});
