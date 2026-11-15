<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\Shape;

/**
 * The effective-rate lookup, a pure function (AC-7): the tax twin of the price
 * ladder.
 *
 * The resolved row is the one with the **latest** `effectiveFrom` that is on or
 * before the document date and whose `effectiveTo` is null or **after** the
 * date (so a row ending 2025-01-01 does not apply on 2025-01-01). No qualifying
 * row is `no_effective_rate`, never a silent zero: an invoice must never
 * compute tax as zero because nobody dated a rate.
 *
 * Dates are `Y-m-d` strings, which order correctly by construction, and the
 * result does not depend on the order the rows arrive in. That holds because two
 * rows sharing an `effectiveFrom` are refused `invalid_amount` before any winner
 * is chosen, whether or not either qualifies (D-36, the same shape as D-31): with
 * a tie on the winning key, the first row to arrive would otherwise win. The
 * unique `(tax_id, effective_from)` key stops this in real data; the check keeps
 * the pure function a function of the row set for FP-432.
 */
final class RateResolver
{
    /**
     * @param  list<RateRow>  $rows
     *
     * @throws PricingRefused
     */
    public static function resolve(array $rows, string $date, string $label = 'tax'): RateRow
    {
        Shape::date($date, 'date');

        $starts = [];

        foreach ($rows as $row) {
            if (isset($starts[$row->effectiveFrom])) {
                throw PricingRefused::invalidAmount("Two rates of [{$label}] share the effective_from {$row->effectiveFrom}.");
            }

            $starts[$row->effectiveFrom] = true;
        }

        $winner = null;

        foreach ($rows as $row) {
            if ($row->effectiveFrom > $date) {
                continue;
            }

            if ($row->effectiveTo !== null && $row->effectiveTo <= $date) {
                continue;
            }

            if ($winner === null || $row->effectiveFrom > $winner->effectiveFrom) {
                $winner = $row;
            }
        }

        if ($winner === null) {
            throw PricingRefused::noEffectiveRate("No rate of [{$label}] is effective on {$date}.");
        }

        return $winner;
    }
}
