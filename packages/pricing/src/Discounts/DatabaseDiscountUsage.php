<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Discounts;

use Flexipos\Contracts\Pricing\DiscountUsage;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Pricing\Support\TenantConnection;
use Illuminate\Support\Str;

/**
 * {@see DiscountUsage}, over `discounts.used_count` (T-030 AC-41, D-37, R-15).
 *
 * **One keyed locking read, then one `UPDATE`.** The first and only read is
 * `where id = ? and deleted_at is null ... for update`, so two completions
 * consuming the last use of one discount take turns: the second reads the count
 * the first committed and is refused. The write is an assignment of the
 * incremented value read under that lock, never an `increment()` on a value read
 * before it. The file sits in pricing's read path, so it orders nothing, limits
 * nothing and sorts nothing (`QaPricingSourceRulesTest`).
 *
 * `used_count` is `INT UNSIGNED`, so a stored 4294967295 cannot be incremented:
 * that is `out_of_range`, and nothing is written. `updated_at` is deliberately
 * left alone, because taking a use is not an edit of the discount.
 */
final class DatabaseDiscountUsage implements DiscountUsage
{
    /** `INT UNSIGNED`'s maximum. */
    private const COLUMN_MAX = 4294967295;

    public function __construct(
        private readonly TenantConnection $tenant,
    ) {}

    public function consume(string $discountId): void
    {
        $connection = $this->tenant->get();

        if (mb_strlen($discountId) !== 26 || ! Str::isUlid($discountId)) {
            throw PricingRefused::unknownDiscount('The discount id is not a 26-character ULID.');
        }

        $id = strtoupper($discountId);

        $row = $connection->table('discounts')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw PricingRefused::unknownDiscount("No discount has id [{$id}].");
        }

        $used = (int) $row->used_count;

        if ($row->usage_limit !== null && $used >= (int) $row->usage_limit) {
            throw PricingRefused::usageLimitReached("The discount [{$id}] has been used {$used} of {$row->usage_limit} times.");
        }

        if ($used >= self::COLUMN_MAX) {
            throw PricingRefused::outOfRange("The discount [{$id}] has a use count that cannot be incremented.");
        }

        $connection->table('discounts')->where('id', $id)->update(['used_count' => $used + 1]);
    }
}
