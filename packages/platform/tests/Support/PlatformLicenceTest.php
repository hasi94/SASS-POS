<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use DateTimeImmutable;
use Flexipos\Platform\Support\PlatformLicence;

/*
|--------------------------------------------------------------------------
| T-016 step 3 - PlatformLicence::verdict(), Ruling 16 (AC-24 to AC-27)
|--------------------------------------------------------------------------
*/

function licenceRow(string $status, ?string $validFrom = null, ?string $validUntil = null): object
{
    return (object) ['status' => $status, 'valid_from' => $validFrom, 'valid_until' => $validUntil];
}

it('is none when there are no rows at all', function () {
    expect(PlatformLicence::verdict([], new DateTimeImmutable('2026-01-01')))->toBe(PlatformLicence::NONE);
});

it('is valid when an active row has no window, or an open one', function () {
    $now = new DateTimeImmutable('2026-06-01 00:00:00.000');

    expect(PlatformLicence::verdict([licenceRow('active')], $now))->toBe(PlatformLicence::VALID)
        ->and(PlatformLicence::verdict([licenceRow('active', '2026-01-01 00:00:00.000', '2026-12-01 00:00:00.000')], $now))->toBe(PlatformLicence::VALID);
});

it('is lapsed when every row is expired, revoked, suspended, or not yet valid', function () {
    $now = new DateTimeImmutable('2026-06-01 00:00:00.000');

    expect(PlatformLicence::verdict([licenceRow('expired')], $now))->toBe(PlatformLicence::LAPSED)
        ->and(PlatformLicence::verdict([licenceRow('revoked')], $now))->toBe(PlatformLicence::LAPSED)
        ->and(PlatformLicence::verdict([licenceRow('suspended')], $now))->toBe(PlatformLicence::LAPSED)
        ->and(PlatformLicence::verdict([licenceRow('active', '2027-01-01 00:00:00.000')], $now))->toBe(PlatformLicence::LAPSED);
});

it('treats valid_until as passed at the boundary and valid one millisecond before it', function () {
    $validUntil = '2026-06-01 00:00:00.000';

    $before = new DateTimeImmutable('2026-05-31 23:59:59.999');
    $at = new DateTimeImmutable('2026-06-01 00:00:00.000');
    $after = new DateTimeImmutable('2026-06-01 00:00:00.001');

    $rows = [licenceRow('active', null, $validUntil)];

    expect(PlatformLicence::verdict($rows, $before))->toBe(PlatformLicence::VALID)
        ->and(PlatformLicence::verdict($rows, $at))->toBe(PlatformLicence::LAPSED)
        ->and(PlatformLicence::verdict($rows, $after))->toBe(PlatformLicence::LAPSED);
});
