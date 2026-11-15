<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Flexipos\Eventbus\Support\Backoff;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 6 - the shared exponential back-off formula (D-8, Ruling 6)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('computes delay(1..6) with base 30 and cap 600 as 30, 60, 120, 240, 480, 600', function () {
    $delays = array_map(static fn (int $attempt): int => Backoff::delaySeconds($attempt, 30, 600), range(1, 6));

    expect($delays)->toBe([30, 60, 120, 240, 480, 600]);
});

it('moves with the base and the cap arguments - no literal is hard-coded', function () {
    expect(Backoff::delaySeconds(1, 10, 1000))->toBe(10)
        ->and(Backoff::delaySeconds(4, 10, 1000))->toBe(80)
        ->and(Backoff::delaySeconds(10, 10, 50))->toBe(50);
});

it('floors the attempt number at 1', function () {
    expect(Backoff::delaySeconds(0, 30, 600))->toBe(30)
        ->and(Backoff::delaySeconds(-5, 30, 600))->toBe(30);
});
