<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Support\BusinessClock;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| T-034 step 6 - BusinessClock (D-53c, AC-40)
|--------------------------------------------------------------------------
|
| Pure: the branch-local business date, read from the branch's own IANA zone.
*/

it('gives the branch-local date, which is not the UTC date around midnight', function (string $now, string $zone, string $expected) {
    $clock = new BusinessClock(RunnerClock::fixedAt($now));

    expect($clock->businessDate($zone))->toBe($expected);
})->with([
    'Asia/Colombo past its midnight, still the day before in UTC' => ['2026-11-12 22:00:00.000', 'Asia/Colombo', '2026-11-13'],
    'the same instant in UTC' => ['2026-11-12 22:00:00.000', 'UTC', '2026-11-12'],
    'Asia/Colombo just before its midnight' => ['2026-11-12 18:29:59.999', 'Asia/Colombo', '2026-11-12'],
    'Asia/Colombo exactly at its midnight' => ['2026-11-12 18:30:00.000', 'Asia/Colombo', '2026-11-13'],
    'a zone behind UTC, before its midnight' => ['2026-11-13 02:00:00.000', 'America/New_York', '2026-11-12'],
    'a zone far ahead' => ['2026-11-12 12:00:00.000', 'Pacific/Auckland', '2026-11-13'],
]);

it('refuses a timezone that is not an IANA zone rather than guessing a date', function () {
    $clock = new BusinessClock(RunnerClock::fixedAt('2026-11-12 22:00:00.000'));

    expect(fn () => $clock->businessDate('Not/AZone'))->toThrow(InvalidArgumentException::class);
});
