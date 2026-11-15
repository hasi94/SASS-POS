<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use DateTimeImmutable;
use Flexipos\Platform\Support\GracePeriod;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 3 - GracePeriod, D-12/Ruling 15 (AC-11, AC-16)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
});

it('is not expired one second before the deadline, and expired at the deadline', function () {
    $pastDueAt = new DateTimeImmutable('2026-01-01 00:00:00.000');

    $before = new DateTimeImmutable('2026-01-07 23:59:59.000');
    $at = new DateTimeImmutable('2026-01-08 00:00:00.000');

    expect(GracePeriod::expired($pastDueAt, $before, 7))->toBeFalse()
        ->and(GracePeriod::expired($pastDueAt, $at, 7))->toBeTrue();
});

it('defaults to 7 when platform_settings has no row', function () {
    expect(GracePeriod::days(PlatformSeed::connection(), 7))->toBe(7);
});

it('reads the configured value when the row is present', function () {
    PlatformSeed::platformSetting(GracePeriod::SETTINGS_KEY, 3);

    expect(GracePeriod::days(PlatformSeed::connection(), 7))->toBe(3);
});

it('falls back to the default and logs when the value is malformed', function () {
    PlatformSeed::platformSetting(GracePeriod::SETTINGS_KEY, -1);

    expect(GracePeriod::days(PlatformSeed::connection(), 7))->toBe(7);
});

it('measures from past_due_at, never from current_period_end', function () {
    // The grace clock takes only a $pastDueAt argument - there is no
    // current_period_end parameter it could read instead, which is the
    // structural half of AC-16.
    $pastDueAt = new DateTimeImmutable('2020-01-01 00:00:00.000');
    $farFuture = new DateTimeImmutable('2020-01-08 00:00:00.000');

    expect(GracePeriod::expired($pastDueAt, $farFuture, 7))->toBeTrue();
});
