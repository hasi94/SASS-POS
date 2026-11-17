<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Units;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\ResolvedFactor;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;

/**
 * The selection behind `UnitResolver::snapshotInherited()` (T-030 D-32, F-7;
 * `docs/database.md` section 6.5): a referencing line - a refund line - takes
 * the factor of the line it refers to, and **never re-walks the ladder**.
 *
 * **Pure**, no database and no float. Exactly three outcomes, in this order:
 *
 * 1. The requested unit is the product's own **stock** unit: the factor is
 *    `1.000000`, source `stock_unit`. This is decided first, so the referenced
 *    factor a caller passes can never change what a stock-unit line converts
 *    by. The stock unit is read by the resolver, never trusted from a caller,
 *    and it is immutable on a product, so the answer cannot drift between a
 *    sale and its refund.
 * 2. The requested unit is the referenced line's unit: that line's own
 *    `unit_factor`, canonicalised to six decimals, source `inherited`.
 * 3. Any other unit: refused `inherited_unit_mismatch`. There is no third
 *    path, because the only other route to a factor is the ladder.
 *
 * The caller may hold the stored factor as a number the database driver
 * returned (`"24"`, `"24.0"`), so it is canonicalised here, and a value that
 * cannot be a factor is refused with a named reason and never escapes as an
 * `InvalidArgumentException`.
 */
final class InheritedFactor
{
    /**
     * @throws UnitResolutionRefused
     */
    public static function select(
        string $productId,
        string $stockUnitId,
        string $referencedUnitId,
        string $referencedUnitFactor,
        string $unitId,
    ): ResolvedFactor {
        if ($unitId === $stockUnitId) {
            return new ResolvedFactor($productId, $unitId, $stockUnitId, '1.000000', FactorSource::StockUnit);
        }

        if ($unitId === $referencedUnitId) {
            return new ResolvedFactor($productId, $unitId, $stockUnitId, self::canonical($referencedUnitFactor), FactorSource::Inherited);
        }

        throw UnitResolutionRefused::inheritedUnitMismatch(
            "The unit [{$unitId}] is neither the referenced line's unit [{$referencedUnitId}] nor the product's stock unit [{$stockUnitId}]."
        );
    }

    /**
     * The factor as exactly six decimals, or a named refusal.
     *
     * @throws UnitResolutionRefused
     */
    public static function canonical(string $factor): string
    {
        if (preg_match('/^\d+(\.\d+)?$/', $factor) !== 1) {
            throw UnitResolutionRefused::factorBelowOne("The referenced factor [{$factor}] is not a plain decimal of at least 1.");
        }

        try {
            $value = BigDecimal::of($factor);
            $rounded = $value->toScale(6, RoundingMode::Unnecessary);
        } catch (MathException) {
            throw UnitResolutionRefused::inexactFactor("The referenced factor [{$factor}] has no exact 6-decimal form.");
        }

        if ($rounded->isLessThan(1)) {
            throw UnitResolutionRefused::factorBelowOne("The referenced factor [{$factor}] is below 1.");
        }

        // DECIMAL(18,6) holds at most 12 integer digits.
        if (strlen($rounded->toScale(0, RoundingMode::Down)->__toString()) > 12) {
            throw UnitResolutionRefused::factorOutOfRange("The referenced factor [{$factor}] does not fit DECIMAL(18,6).");
        }

        return $rounded->__toString();
    }
}
