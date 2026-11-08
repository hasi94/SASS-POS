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
 * The five-step conversion ladder, exactly (T-027 plan Ruling 3;
 * `docs/database.md` §6.3, AC-9, AC-10, AC-15).
 *
 * **Pure.** `DatabaseUnitResolver` loads the `UnitCatalogue` and the (at
 * most) two candidate `unit_conversions` rows - product-scoped, then
 * global, already looked up by the exact key the ladder reads
 * (`from_unit_id = fromUnit, to_unit_id = stockUnit`, F-7) - and hands them
 * here. Nothing in this class touches a connection.
 */
final class UnitLadder
{
    /** `DECIMAL(18,6)`'s largest value. */
    private const MAX_FACTOR = '999999999999.999999';

    /**
     * @throws UnitResolutionRefused
     */
    public static function resolve(
        UnitCatalogue $units,
        string $productId,
        string $fromUnitId,
        string $stockUnitId,
        ?ConversionRow $productConversion,
        ?ConversionRow $globalConversion,
    ): ResolvedFactor {
        $fromDimension = $units->dimension($fromUnitId);
        $stockDimension = $units->dimension($stockUnitId);

        if ($fromDimension === null) {
            throw UnitResolutionRefused::unknownUnit("The unit [{$fromUnitId}] does not exist.");
        }

        if ($stockDimension === null) {
            throw UnitResolutionRefused::unknownUnit("The unit [{$stockUnitId}] does not exist.");
        }

        // AC-10: one dimension pre-check before every later step, so a
        // hand-inserted cross-dimension row can never be read as valid
        // either.
        if ($fromDimension !== $stockDimension) {
            throw UnitResolutionRefused::crossDimension(
                "The unit [{$fromUnitId}] ({$fromDimension->value}) does not share a dimension with the stock unit [{$stockUnitId}] ({$stockDimension->value})."
            );
        }

        // Step 1: a product-scoped row.
        if ($productConversion !== null) {
            return self::guard($productConversion->factor, FactorSource::ProductConversion, $productConversion->id, $productId, $fromUnitId, $stockUnitId);
        }

        // Step 2: a global row.
        if ($globalConversion !== null) {
            return self::guard($globalConversion->factor, FactorSource::GlobalConversion, $globalConversion->id, $productId, $fromUnitId, $stockUnitId);
        }

        // Step 3: the base-unit chain, only when fromUnit is not already
        // the stock unit.
        if ($fromUnitId !== $stockUnitId) {
            $fromRoot = self::walkToRoot($units, $fromUnitId);
            $stockRoot = self::walkToRoot($units, $stockUnitId);

            if ($fromRoot !== null && $stockRoot !== null && $fromRoot[0] === $stockRoot[0]) {
                try {
                    $factor = $fromRoot[1]->dividedBy($stockRoot[1], 6, RoundingMode::Unnecessary);
                } catch (MathException) {
                    throw UnitResolutionRefused::inexactFactor(
                        "The unit chain from [{$fromUnitId}] to [{$stockUnitId}] has no exact 6-decimal factor."
                    );
                }

                return self::guard($factor->__toString(), FactorSource::UnitChain, null, $productId, $fromUnitId, $stockUnitId);
            }
        }

        // Step 4: fromUnit is the stock unit itself.
        if ($fromUnitId === $stockUnitId) {
            return self::guard('1.000000', FactorSource::StockUnit, null, $productId, $fromUnitId, $stockUnitId);
        }

        // Step 5: exhausted. Never a default of 1 (AC-15).
        throw UnitResolutionRefused::noConversionPath(
            "No conversion path from [{$fromUnitId}] to the stock unit [{$stockUnitId}] for product [{$productId}]."
        );
    }

    /**
     * Walk from `$unitId` to its chain root, multiplying `units.factor`
     * along the way. A cycle (only reachable by a hand insert, Ruling 6)
     * falls through to null, guarded by the visited set, rather than
     * looping.
     *
     * @return array{0: string, 1: BigDecimal}|null
     */
    private static function walkToRoot(UnitCatalogue $units, string $unitId): ?array
    {
        $visited = [];
        $current = $unitId;
        $cumulative = BigDecimal::one();

        while (true) {
            if (isset($visited[$current])) {
                return null;
            }

            $visited[$current] = true;
            $base = $units->baseOf($current);

            if ($base === null) {
                return [$current, $cumulative];
            }

            $cumulative = $cumulative->multipliedBy(BigDecimal::of($base['factor']));
            $current = $base['baseUnitId'];
        }
    }

    /**
     * AC-9's output guard, applied to whatever step 1 to 4 produced:
     * `factor < 1` is refused, and so is a factor over `DECIMAL(18,6)`'s
     * range.
     *
     * @throws UnitResolutionRefused
     */
    private static function guard(
        string $factor,
        FactorSource $source,
        ?string $conversionId,
        string $productId,
        string $fromUnitId,
        string $stockUnitId,
    ): ResolvedFactor {
        $value = BigDecimal::of($factor);

        if ($value->compareTo(BigDecimal::one()) < 0) {
            throw UnitResolutionRefused::factorBelowOne("The resolved factor [{$factor}] is below 1.");
        }

        if ($value->compareTo(BigDecimal::of(self::MAX_FACTOR)) > 0) {
            throw UnitResolutionRefused::factorOutOfRange("The resolved factor [{$factor}] does not fit DECIMAL(18,6).");
        }

        $canonical = $value->toScale(6, RoundingMode::Unnecessary)->__toString();

        return new ResolvedFactor($productId, $fromUnitId, $stockUnitId, $canonical, $source, $conversionId);
    }
}
