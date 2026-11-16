<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Contracts\Pricing\PricedPurchaseLine;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\PurchaseLinePricing;
use Flexipos\Contracts\Pricing\PurchaseLineQuote;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/**
 * The one place an order line becomes a quoted line (T-034 AC-31, AC-42, AC-43,
 * AC-49, D-14, D-53a): the unit quad from `UnitResolver`, every amount from
 * `PurchaseLinePricing`, nothing computed here.
 *
 * - {@see self::quote()} resolves the quad fresh with `snapshot()`: a new line,
 *   or an edit that changed the unit or the quantity.
 * - {@see self::quoteInherited()} resolves it with `snapshotInherited()`, the
 *   request conversion's way (AC-31): the stored factor of the request line
 *   survives a ladder that has since moved.
 * - {@see self::price()} re-quotes a quad the line already holds, when only the
 *   cost, the discount or the group changed (AC-49).
 *
 * **The pricing date is the caller's.** Every method takes `$pricingDate`
 * (`Y-m-d`), which is the order's own date today: the rate a tax group had on
 * that day applies, whatever day the line was typed in. A later caller (the
 * receiving task) may price at a different date without this class changing.
 *
 * **Refusals.** A resolver refusal is `unit_unresolvable`, or
 * `inherited_unit_mismatch` for that one cause, the resolver's reason attached. A
 * pricing refusal is `pricing_refused` with the engine's reason attached, except
 * overflow, which is `out_of_range` (AC-47) with the engine's reason attached
 * too. Nothing is written here, so a refusal leaves the caller's transaction
 * free to be never opened.
 *
 * Reads only, on autocommit, before the write transaction (Ruling 7). Five
 * queries for `snapshot()` and two for `snapshotInherited()` are the resolver's
 * (FP-426); this class adds the pricing adapter's own reads and no more.
 */
final class LineQuoter
{
    public function __construct(
        private readonly UnitResolver $units,
        private readonly PurchaseLinePricing $pricing,
    ) {}

    /**
     * @throws PurchasingRefused unit_unresolvable, pricing_refused, out_of_range
     */
    public function quote(
        string $productId,
        string $unitId,
        string $quantity,
        string $unitCost,
        string $discountPercent,
        string $taxGroupId,
        string $pricingDate,
    ): QuotedLine {
        try {
            $unit = $this->units->snapshot($productId, $unitId, $quantity);
        } catch (UnitResolutionRefused $refused) {
            throw PurchasingRefused::fromUnit($refused);
        }

        return $this->priced($unit, $unitCost, $discountPercent, $taxGroupId, $pricingDate);
    }

    /**
     * @throws PurchasingRefused unit_unresolvable, inherited_unit_mismatch, pricing_refused, out_of_range
     */
    public function quoteInherited(
        string $productId,
        string $referencedUnitId,
        string $referencedUnitFactor,
        string $unitId,
        string $quantity,
        string $unitCost,
        string $discountPercent,
        string $taxGroupId,
        string $pricingDate,
    ): QuotedLine {
        try {
            $unit = $this->units->snapshotInherited($productId, $referencedUnitId, $referencedUnitFactor, $unitId, $quantity);
        } catch (UnitResolutionRefused $refused) {
            throw PurchasingRefused::fromUnit($refused);
        }

        return $this->priced($unit, $unitCost, $discountPercent, $taxGroupId, $pricingDate);
    }

    /**
     * Re-quote a stored quad under a new cost, discount or group, without
     * re-resolving the unit (AC-49).
     *
     * @throws PurchasingRefused pricing_refused, out_of_range
     */
    public function price(
        string $quantity,
        string $baseQuantity,
        string $unitCost,
        string $discountPercent,
        string $taxGroupId,
        string $pricingDate,
    ): PricedPurchaseLine {
        try {
            return $this->pricing->quotePurchaseLine(
                new PurchaseLineQuote($quantity, $unitCost, $discountPercent, $taxGroupId, $baseQuantity, $pricingDate)
            );
        } catch (PricingRefused $refused) {
            if ($refused->reason === PricingRefusal::OutOfRange) {
                throw PurchasingRefused::because(PurchasingRefusal::OutOfRange, $refused->getMessage(), $refused->reason);
            }

            throw PurchasingRefused::fromPricing($refused);
        }
    }

    private function priced(
        UnitSnapshot $unit,
        string $unitCost,
        string $discountPercent,
        string $taxGroupId,
        string $pricingDate,
    ): QuotedLine {
        $priced = $this->price($unit->quantity, $unit->baseQuantity, $unitCost, $discountPercent, $taxGroupId, $pricingDate);

        return new QuotedLine($unit, $unitCost, $discountPercent, $taxGroupId, $priced);
    }
}
