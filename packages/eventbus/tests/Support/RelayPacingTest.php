<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Flexipos\Eventbus\Support\RelayPacing;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 6 - in-process back-off pacing, with no real wait (AC-17)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('is due with no entry, not due immediately after being marked, and due once the delay elapses', function () {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $pacing = new RelayPacing($clock);

    expect($pacing->isDue('tenant-a', 'row-1'))->toBeTrue();

    $pacing->markDueAfter('tenant-a', 'row-1', 30);

    expect($pacing->isDue('tenant-a', 'row-1'))->toBeFalse();

    $clock->advanceSeconds(29);
    expect($pacing->isDue('tenant-a', 'row-1'))->toBeFalse();

    $clock->advanceSeconds(1);
    expect($pacing->isDue('tenant-a', 'row-1'))->toBeTrue();
});

it('forgets a row, so it is due again with no trace of the previous mark', function () {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $pacing = new RelayPacing($clock);

    $pacing->markDueAfter('tenant-a', 'row-1', 600);
    $pacing->forget('tenant-a', 'row-1');

    expect($pacing->isDue('tenant-a', 'row-1'))->toBeTrue();
});

it('keys by tenant and row independently', function () {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $pacing = new RelayPacing($clock);

    $pacing->markDueAfter('tenant-a', 'row-1', 600);

    expect($pacing->isDue('tenant-a', 'row-1'))->toBeFalse()
        ->and($pacing->isDue('tenant-b', 'row-1'))->toBeTrue()
        ->and($pacing->isDue('tenant-a', 'row-2'))->toBeTrue();
});
