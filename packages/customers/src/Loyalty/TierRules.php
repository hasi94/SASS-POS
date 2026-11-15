<?php

declare(strict_types=1);

namespace Flexipos\Customers\Loyalty;

use Brick\Math\BigDecimal;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Support\Decimal;

/**
 * `loyalty_programs.tier_rules`'s one validated minimal shape (T-028 plan
 * Ruling 5, D-22, F-5).
 *
 * `null`, or a non-empty list of `{tier: string <= 50 chars, min_points:
 * decimal string >= 0, <= 4dp}` objects, with unique tier names and unique
 * thresholds. The tier for a balance is the entry with the greatest
 * `min_points` at or below it; no such entry, or `null` rules, gives
 * `null`. `earn_rule`/`redeem_rule` stay fully opaque - nothing here
 * evaluates either.
 */
final class TierRules
{
    /**
     * @return ?list<array{tier: string, min_points: string}>
     *
     * @throws CustomersRefused
     */
    public static function parse(mixed $json): ?array
    {
        if ($json === null) {
            return null;
        }

        if (! is_array($json) || ! array_is_list($json) || $json === []) {
            throw CustomersRefused::validation('[tier_rules] must be null, or a non-empty list of {tier, min_points} objects.');
        }

        $seenTiers = [];
        $seenThresholds = [];
        $parsed = [];

        foreach ($json as $row) {
            if (! is_array($row) || ! array_key_exists('tier', $row) || ! array_key_exists('min_points', $row)) {
                throw CustomersRefused::validation('[tier_rules] every entry must have a tier and a min_points key.');
            }

            $tier = $row['tier'];

            if (! is_string($tier) || $tier === '' || mb_strlen($tier) > 50) {
                throw CustomersRefused::validation('[tier_rules] tier must be 1 to 50 characters.');
            }

            $minPointsRaw = $row['min_points'];

            if (! is_int($minPointsRaw) && ! is_string($minPointsRaw)) {
                throw CustomersRefused::validation('[tier_rules] min_points must be an int or a decimal string.');
            }

            $minPoints = Decimal::parseUnsigned($minPointsRaw);

            if ($minPoints === null) {
                throw CustomersRefused::validation("[tier_rules] min_points [{$minPointsRaw}] must be a non-negative DECIMAL(18,4) value.");
            }

            if (isset($seenTiers[$tier])) {
                throw CustomersRefused::validation("[tier_rules] the tier [{$tier}] is duplicated.");
            }

            $canonicalThreshold = Decimal::canonical($minPoints);

            if (isset($seenThresholds[$canonicalThreshold])) {
                throw CustomersRefused::validation("[tier_rules] the min_points value [{$canonicalThreshold}] is duplicated.");
            }

            $seenTiers[$tier] = true;
            $seenThresholds[$canonicalThreshold] = true;

            $parsed[] = ['tier' => $tier, 'min_points' => $canonicalThreshold];
        }

        return $parsed;
    }

    /**
     * The greatest `min_points` at or below `$balance`, or null if `$rules`
     * is null or no entry qualifies.
     *
     * @param  ?list<array{tier: string, min_points: string}>  $rules
     */
    public static function tierFor(?array $rules, string $balance): ?string
    {
        if ($rules === null) {
            return null;
        }

        $balanceAmount = BigDecimal::of($balance);
        $bestTier = null;
        $bestThreshold = null;

        foreach ($rules as $row) {
            $threshold = BigDecimal::of($row['min_points']);

            if ($threshold->compareTo($balanceAmount) > 0) {
                continue;
            }

            if ($bestThreshold === null || $threshold->compareTo($bestThreshold) > 0) {
                $bestThreshold = $threshold;
                $bestTier = $row['tier'];
            }
        }

        return $bestTier;
    }
}
