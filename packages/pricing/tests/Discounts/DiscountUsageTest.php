<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Discounts;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Pricing\DiscountUsage;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Pricing\Discounts\DatabaseDiscountUsage;
use Flexipos\Pricing\Tests\Support\LockMarkingGrammar;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 5 - DiscountUsage (AC-41, AC-58; D-37, D-54; F-25's reading,
| R-15, Q-11)
|--------------------------------------------------------------------------
|
| The one writer of `discounts.used_count`. Exactly `usage_limit` uses
| succeed (the engine refuses at the same boundary); an unknown or
| soft-deleted discount is `unknown_discount`; the count at the column's
| maximum is `out_of_range`; and `is_active` and the window are quote-time
| facts that `consume()` deliberately does not re-check (Q-11).
|
| The lock is pinned with LockMarkingGrammar, which writes the clause SQLite
| drops back as a comment, so the statements execute and the log shows
| which of them were locking reads. The read is the FIRST statement.
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

function duUsage(): DiscountUsage
{
    return app(DiscountUsage::class);
}

function duCount(string $discountId): int
{
    return (int) PricingSeed::connection()->table('discounts')->where('id', $discountId)->value('used_count');
}

/**
 * @return PricingRefusal the reason the call was refused
 */
function duRefusal(string $discountId): PricingRefusal
{
    try {
        duUsage()->consume($discountId);
    } catch (PricingRefused $refused) {
        return $refused->reason;
    }

    Assert::fail('Expected a refusal.');
}

it('binds the contract to the database implementation', function () {
    expect(duUsage())->toBeInstanceOf(DatabaseDiscountUsage::class);
});

it('increments used_count by exactly one', function () {
    $id = PricingSeed::discount('D1');

    duUsage()->consume($id);

    expect(duCount($id))->toBe(1);

    duUsage()->consume(strtolower($id));

    expect(duCount($id))->toBe(2);
});

it('lets exactly usage_limit uses succeed: with a limit of 2 the third refuses and writes nothing (F-25)', function () {
    $id = PricingSeed::discount('D2', ['usage_limit' => 2]);

    duUsage()->consume($id);
    duUsage()->consume($id);

    expect(duCount($id))->toBe(2)
        ->and(duRefusal($id))->toBe(PricingRefusal::UsageLimitReached)
        ->and(duCount($id))->toBe(2);
});

it('refuses at once when the limit is already reached, including a limit of zero', function (int $limit, int $used) {
    $id = PricingSeed::discount('D3', ['usage_limit' => $limit, 'used_count' => $used]);

    expect(duRefusal($id))->toBe(PricingRefusal::UsageLimitReached)
        ->and(duCount($id))->toBe($used);
})->with([
    'at the limit' => [5, 5],
    'beyond the limit' => [5, 9],
    'a limit of zero' => [0, 0],
]);

it('never refuses a discount with no limit', function () {
    $id = PricingSeed::discount('D4', ['usage_limit' => null, 'used_count' => 1000]);

    duUsage()->consume($id);

    expect(duCount($id))->toBe(1001);
});

it('refuses an unknown or soft-deleted discount as unknown_discount, and a malformed id the same way', function () {
    $deleted = PricingSeed::discount('D5', ['deleted_at' => PricingSeed::NOW, 'used_count' => 3]);

    expect(duRefusal('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->toBe(PricingRefusal::UnknownDiscount)
        ->and(duRefusal($deleted))->toBe(PricingRefusal::UnknownDiscount)
        ->and(duCount($deleted))->toBe(3)
        ->and(duRefusal('not-a-ulid'))->toBe(PricingRefusal::UnknownDiscount)
        ->and(duRefusal(''))->toBe(PricingRefusal::UnknownDiscount);
});

it('refuses a count already at the column maximum as out_of_range, and writes nothing (R-15)', function () {
    $id = PricingSeed::discount('D6', ['usage_limit' => null, 'used_count' => 4294967295]);

    expect(duRefusal($id))->toBe(PricingRefusal::OutOfRange)
        ->and(duCount($id))->toBe(4294967295);

    // One below the maximum still increments, so the boundary is exact.
    $below = PricingSeed::discount('D7', ['usage_limit' => null, 'used_count' => 4294967294]);

    duUsage()->consume($below);

    expect(duCount($below))->toBe(4294967295);
});

it('consumes an inactive discount and one outside its window: those are quote-time facts (Q-11, AC-58)', function () {
    $inactive = PricingSeed::discount('D8', ['is_active' => false]);
    $expired = PricingSeed::discount('D9', ['starts_at' => '2020-01-01 00:00:00.000', 'ends_at' => '2020-12-31 23:59:59.999']);
    $future = PricingSeed::discount('D10', ['starts_at' => '2099-01-01 00:00:00.000']);

    foreach ([$inactive, $expired, $future] as $id) {
        duUsage()->consume($id);

        expect(duCount($id))->toBe(1);
    }
});

it('touches no other discount and leaves updated_at alone', function () {
    $one = PricingSeed::discount('D11');
    $two = PricingSeed::discount('D12');

    duUsage()->consume($one);

    expect(duCount($two))->toBe(0)
        ->and(PricingSeed::row('discounts', $one)['updated_at'])->toBe(PricingSeed::NOW);
});

it('reads the discount row FOR UPDATE as its first statement, then assigns the incremented value', function () {
    $id = PricingSeed::discount('D13', ['usage_limit' => 3, 'used_count' => 1]);
    $connection = PricingSeed::connection();
    $standIn = $connection->getQueryGrammar();
    $log = [];

    $connection->setQueryGrammar(new LockMarkingGrammar($connection));
    $connection->listen(static function (QueryExecuted $query) use (&$log): void {
        $log[] = $query->sql;
    });

    try {
        duUsage()->consume($id);
    } finally {
        $connection->setQueryGrammar($standIn);
    }

    expect($log)->toHaveCount(2)
        ->and($log[0])->toStartWith('select')
        ->and($log[0])->toContain(LockMarkingGrammar::FOR_UPDATE)
        ->and($log[0])->toContain('"deleted_at" is null')
        ->and($log[1])->toStartWith('update "discounts" set "used_count" = ?')
        // An assignment of the value read under the lock, never an increment expression.
        ->and($log[1])->not->toContain('"used_count" + 1')
        ->and(duCount($id))->toBe(2);
});

it('writes nothing at all when it refuses: one read, no update', function () {
    $id = PricingSeed::discount('D14', ['usage_limit' => 1, 'used_count' => 1]);
    $connection = PricingSeed::connection();
    $log = [];

    $connection->listen(static function (QueryExecuted $query) use (&$log): void {
        $log[] = $query->sql;
    });

    duRefusal($id);

    expect($log)->toHaveCount(1)
        ->and($log[0])->toStartWith('select');
});
