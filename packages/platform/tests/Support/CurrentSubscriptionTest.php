<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use Flexipos\Platform\Support\CurrentSubscription;

/*
|--------------------------------------------------------------------------
| T-016 step 3 - CurrentSubscription::pick(), Ruling 5 (AC-14)
|--------------------------------------------------------------------------
*/

function currentSubscriptionRow(string $id, ?string $periodStart): object
{
    return (object) ['id' => $id, 'current_period_start' => $periodStart, 'status' => 'active'];
}

/**
 * @param  list<object>  $rows
 */
function currentSubscriptionPickedId(array $rows): string
{
    $picked = CurrentSubscription::pick($rows);

    expect($picked)->not->toBeNull();

    return (string) $picked->id;
}

it('returns null for no rows', function () {
    expect(CurrentSubscription::pick([]))->toBeNull();
});

it('picks the row with the latest current_period_start among three historical and one current', function () {
    $rows = [
        currentSubscriptionRow('A', '2026-01-01 00:00:00.000'),
        currentSubscriptionRow('B', '2026-06-01 00:00:00.000'),
        currentSubscriptionRow('C', '2026-03-01 00:00:00.000'),
        currentSubscriptionRow('D', '2026-09-01 00:00:00.000'),
    ];

    expect(currentSubscriptionPickedId($rows))->toBe('D');
});

it('breaks a tie on current_period_start by the highest id', function () {
    $rows = [
        currentSubscriptionRow('A', '2026-01-01 00:00:00.000'),
        currentSubscriptionRow('C', '2026-01-01 00:00:00.000'),
        currentSubscriptionRow('B', '2026-01-01 00:00:00.000'),
    ];

    expect(currentSubscriptionPickedId($rows))->toBe('C');
});

it('ranks a null current_period_start below every dated row', function () {
    $rows = [
        currentSubscriptionRow('Z', null),
        currentSubscriptionRow('A', '2020-01-01 00:00:00.000'),
    ];

    expect(currentSubscriptionPickedId($rows))->toBe('A');
});

it('picks the highest id among nulls when every row is undated', function () {
    $rows = [currentSubscriptionRow('A', null), currentSubscriptionRow('C', null), currentSubscriptionRow('B', null)];

    expect(currentSubscriptionPickedId($rows))->toBe('C');
});
