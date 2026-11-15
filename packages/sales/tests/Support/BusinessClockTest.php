<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Support\BusinessClock;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| T-030 step 13 - BusinessClock (AC-1, AC-4, AC-27; R-10)
|--------------------------------------------------------------------------
|
| Pure: the instant a quote is made at, the branch-local business date, and a
| stored DATETIME(3) as an instant.
*/

it('renders now as the UTC instant the pricing contract takes', function () {
    $clock = new BusinessClock(RunnerClock::fixedAt('2026-11-12 10:00:00.123'));

    expect($clock->instant())->toBe('2026-11-12T10:00:00.123Z');
});

it('gives the branch-local date, which is not the UTC date around midnight', function (string $now, string $zone, string $expected) {
    $clock = new BusinessClock(RunnerClock::fixedAt($now));

    expect($clock->businessDate($zone))->toBe($expected);
})->with([
    'a zone ahead of UTC, past its midnight' => ['2026-11-12 22:00:00.000', 'Asia/Colombo', '2026-11-13'],
    'the same instant in UTC' => ['2026-11-12 22:00:00.000', 'UTC', '2026-11-12'],
    'a zone behind UTC, before its midnight' => ['2026-11-13 02:00:00.000', 'America/New_York', '2026-11-12'],
    'a zone far ahead' => ['2026-11-12 12:00:00.000', 'Pacific/Auckland', '2026-11-13'],
]);

it('turns a stored DATETIME(3) into an instant, padding or cutting the fraction to milliseconds', function (string $stored, string $expected) {
    expect(BusinessClock::instantOf($stored))->toBe($expected);
})->with([
    'milliseconds' => ['2026-11-12 09:00:00.250', '2026-11-12T09:00:00.250Z'],
    'no fraction' => ['2026-11-12 09:00:00', '2026-11-12T09:00:00.000Z'],
    'microseconds are cut' => ['2026-11-12 09:00:00.123456', '2026-11-12T09:00:00.123Z'],
]);

it('refuses a value that is not a stored timestamp', function (string $stored) {
    expect(fn () => BusinessClock::instantOf($stored))->toThrow(InvalidArgumentException::class);
})->with(['an instant' => ['2026-11-12T09:00:00.250Z'], 'a date' => ['2026-11-12'], 'text' => ['now']]);
