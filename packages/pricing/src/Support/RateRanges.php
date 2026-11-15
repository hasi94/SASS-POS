<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Illuminate\Database\Connection;

/**
 * The AC-6 overlap rule: two rates of one tax may not cover the same day.
 *
 * A rate covers `[effective_from, effective_to)`, so a row closed on 2025-01-01
 * and a row opening on 2025-01-01 do not collide. A null `effective_to` is
 * "forever", so a second open-ended row always collides. The caller holds a
 * `lockForUpdate()` on the parent `taxes` row, so two writers for one tax
 * serialise (a no-op on the SQLite stand-in, `[ENGINE]`).
 *
 * Dates are compared as `Y-m-d` strings, which order correctly by construction.
 * This is the write path's own check, not the pure engine's `RateResolver`.
 */
final class RateRanges
{
    /**
     * @throws PricingAdminRefused overlap
     */
    public static function assertNoOverlap(Connection $connection, string $taxId, string $from, ?string $to, ?string $exceptRateId = null): void
    {
        $query = $connection->table('tax_rates')->where('tax_id', $taxId);

        if ($exceptRateId !== null) {
            $query->where('id', '!=', $exceptRateId);
        }

        foreach ($query->get(['effective_from', 'effective_to']) as $other) {
            $otherFrom = (string) $other->effective_from;
            $otherTo = $other->effective_to === null ? null : (string) $other->effective_to;

            $startsBeforeOtherEnds = $otherTo === null || $from < $otherTo;
            $otherStartsBeforeEnd = $to === null || $otherFrom < $to;

            if ($startsBeforeOtherEnds && $otherStartsBeforeEnd) {
                $window = $otherTo === null ? "from {$otherFrom}, open-ended" : "from {$otherFrom} to {$otherTo}";

                throw PricingAdminRefused::overlap("The range overlaps an existing rate of this tax ({$window}).");
            }
        }
    }
}
