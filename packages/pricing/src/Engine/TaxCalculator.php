<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\Shape;
use Flexipos\Contracts\Pricing\TaxBreakdown;
use Flexipos\Contracts\Pricing\TaxContext;
use Flexipos\Contracts\Pricing\TaxLine;

/**
 * The tax of one amount under one tax group, a pure function (AC-9, AC-11,
 * D-15). Pure: no database, no clock, no container.
 *
 * The fixed order, which FP-432's TypeScript twin must match exactly:
 *
 * 1. **Filter.** A member that is deleted, inactive or whose `applies_to`
 *    excludes the context is skipped. A surviving member with no rate effective
 *    on the date is `no_effective_rate`: being filtered out excuses a tax, having
 *    no rate never does. An empty result is `net = amount`, no lines, total
 *    `0.0000`.
 * 2. **Order** by `sequence`, never by the order the rows arrived in.
 * 3. **Percentage member:** `round(base x rate / 100, 4)`, multiplied exactly and
 *    rounded once. The base is the line amount, and for a **compound** member
 *    the line amount **plus every member amount before it in `sequence`**,
 *    compound or not.
 * 4. **Fixed member:** `round(rate x baseQuantity, 4)`, `rate` being per stock unit.
 * 5. **Exclusive group:** each amount sits on top; `net = amount`.
 * 6. **Inclusive group:** the supplied amount is gross. The fixed amounts come
 *    off first (more than the gross is `inclusive_amount_too_small`). The
 *    percentage members then imply an exact linear relation between the net and
 *    the gross, `gross - fixed = net x M + K`, where `M` is the exact multiplier
 *    their sequence implies (for a group of compound members it is the product
 *    of every `1 + rate/100`) and `K` the exact part that a compound member
 *    charges on a fixed amount before it. `net = round((gross - fixed - K) / M, 4)`
 *    rounds once; the taxes are then computed forward from that net, and the
 *    rounding residue `gross - net - the sum of all amounts` is added to the
 *    **last percentage member in sequence whose resolved rate is not zero**
 *    (D-34), so `net + total = gross` exactly and one predictable member absorbs
 *    the cent. A member at a zero rate is skipped: a residue on it would show a
 *    negative amount or tax on a 0 % line. When every percentage rate is zero
 *    the residue is itself zero.
 */
final class TaxCalculator
{
    /**
     * @param  list<TaxMember>  $members
     *
     * @throws PricingRefused
     */
    public static function compute(
        array $members,
        string $amount,
        string $baseQuantity,
        string $date,
        TaxContext $context,
        bool $inclusive,
    ): TaxBreakdown {
        $gross = Decimal::money($amount, 'amount');
        $quantity = Decimal::money($baseQuantity, 'baseQuantity');
        Shape::date($date, 'date');

        $applied = self::applicable($members, $date, $context);

        if ($applied === []) {
            return new TaxBreakdown($inclusive, Decimal::text($gross), [], '0.0000');
        }

        if (! $inclusive) {
            return self::breakdown(false, $gross, self::forward($applied, $gross, $quantity));
        }

        $net = self::inclusiveNet($applied, $gross, $quantity);
        $computed = self::forward($applied, $net, $quantity);
        $residue = $gross->minus($net)->minus(self::sum($computed));

        if (! $residue->isZero()) {
            $last = self::lastPercentage($computed);

            // A non-zero residue always has a member to land on. With only
            // fixed members, or only zero-rate percentage members, the slope
            // and the constant are zero, so the net is exactly gross - fixed
            // and the residue is zero.
            if ($last !== null) {
                $computed[$last]['amount'] = $computed[$last]['amount']->plus($residue);
            }
        }

        return self::breakdown(true, $net, $computed);
    }

    /**
     * The members that apply, in `sequence` order, each with its resolved rate.
     *
     * @param  list<TaxMember>  $members
     * @return list<array{member: TaxMember, rate: BigDecimal}>
     *
     * @throws PricingRefused
     */
    private static function applicable(array $members, string $date, TaxContext $context): array
    {
        $surviving = array_values(array_filter(
            $members,
            static fn (TaxMember $member): bool => ! $member->isDeleted && $member->isActive && $member->appliesTo->covers($context),
        ));

        // Sequence is unique within a group; the tax id is only a deterministic
        // tiebreak so that a malformed input still orders the same way twice.
        usort($surviving, static fn (TaxMember $a, TaxMember $b): int => ($a->sequence <=> $b->sequence) ?: strcmp($a->taxId, $b->taxId));

        $applied = [];

        foreach ($surviving as $member) {
            $row = RateResolver::resolve($member->rates, $date, $member->code);

            $applied[] = ['member' => $member, 'rate' => Decimal::rate($row->rate, 'rate')];
        }

        return $applied;
    }

    /**
     * Each member's base and amount, computed forward from a net amount.
     *
     * @param  list<array{member: TaxMember, rate: BigDecimal}>  $applied
     * @return list<array{member: TaxMember, rate: BigDecimal, base: BigDecimal, amount: BigDecimal}>
     *
     * @throws PricingRefused
     */
    private static function forward(array $applied, BigDecimal $net, BigDecimal $quantity): array
    {
        $computed = [];
        $running = BigDecimal::zero();

        foreach ($applied as $entry) {
            $member = $entry['member'];
            $rate = $entry['rate'];

            if ($member->type === TaxType::Fixed) {
                $base = $net;
                $amount = Decimal::round($rate->multipliedBy($quantity));
            } else {
                $base = $member->isCompound ? $net->plus($running) : $net;
                $amount = Decimal::percentOf($base, $rate);
            }

            $running = $running->plus($amount);
            $computed[] = ['member' => $member, 'rate' => $rate, 'base' => $base, 'amount' => $amount];
        }

        return $computed;
    }

    /**
     * The tax-exclusive net of an inclusive gross (AC-11.6).
     *
     * @param  list<array{member: TaxMember, rate: BigDecimal}>  $applied
     *
     * @throws PricingRefused
     */
    private static function inclusiveNet(array $applied, BigDecimal $gross, BigDecimal $quantity): BigDecimal
    {
        $fixedTotal = BigDecimal::zero();
        $fixedBefore = BigDecimal::zero();
        $slope = BigDecimal::zero();
        $constant = BigDecimal::zero();

        foreach ($applied as $entry) {
            $member = $entry['member'];

            if ($member->type === TaxType::Fixed) {
                $amount = Decimal::round($entry['rate']->multipliedBy($quantity));
                $fixedTotal = $fixedTotal->plus($amount);
                $fixedBefore = $fixedBefore->plus($amount);

                continue;
            }

            // A percentage as an exact fraction: at most six decimals.
            $fraction = $entry['rate']->withPointMovedLeft(2);

            if ($member->isCompound) {
                $slope = $slope->plus($fraction->multipliedBy(BigDecimal::one()->plus($slope)));
                $constant = $constant->plus($fraction->multipliedBy($constant->plus($fixedBefore)));
            } else {
                $slope = $slope->plus($fraction);
            }
        }

        if ($fixedTotal->compareTo($gross) > 0) {
            throw PricingRefused::inclusiveAmountTooSmall("The fixed taxes [{$fixedTotal}] exceed the inclusive amount [{$gross}].");
        }

        $remaining = $gross->minus($fixedTotal)->minus($constant);

        if ($remaining->isNegative()) {
            throw PricingRefused::inclusiveAmountTooSmall("The inclusive amount [{$gross}] cannot cover the fixed taxes and the tax charged on them.");
        }

        return Decimal::round($remaining->dividedBy(BigDecimal::one()->plus($slope), 4, RoundingMode::HalfUp));
    }

    /**
     * @param  list<array{member: TaxMember, rate: BigDecimal, base: BigDecimal, amount: BigDecimal}>  $computed
     */
    private static function sum(array $computed): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($computed as $entry) {
            $total = $total->plus($entry['amount']);
        }

        return $total;
    }

    /**
     * The last percentage member whose resolved rate is not zero (D-34).
     *
     * @param  list<array{member: TaxMember, rate: BigDecimal, base: BigDecimal, amount: BigDecimal}>  $computed
     */
    private static function lastPercentage(array $computed): ?int
    {
        $last = null;

        foreach ($computed as $index => $entry) {
            if ($entry['member']->type === TaxType::Percentage && ! $entry['rate']->isZero()) {
                $last = $index;
            }
        }

        return $last;
    }

    /**
     * @param  list<array{member: TaxMember, rate: BigDecimal, base: BigDecimal, amount: BigDecimal}>  $computed
     *
     * @throws PricingRefused
     */
    private static function breakdown(bool $inclusive, BigDecimal $net, array $computed): TaxBreakdown
    {
        $lines = [];

        foreach ($computed as $entry) {
            $member = $entry['member'];

            $lines[] = new TaxLine(
                $member->taxId,
                $member->code,
                $member->taxCode,
                $member->type->value,
                $member->isCompound,
                Decimal::text($entry['rate']),
                Decimal::text($entry['base']),
                Decimal::text($entry['amount']),
            );
        }

        return new TaxBreakdown($inclusive, Decimal::text($net), $lines, Decimal::text(self::sum($computed)));
    }
}
