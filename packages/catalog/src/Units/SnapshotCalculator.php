<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Units;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Flexipos\Contracts\Catalog\ResolvedFactor;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitSnapshot;

/**
 * The snapshot arithmetic behind `UnitResolver::snapshot()` (T-027 plan
 * Ruling 4; `docs/database.md` §3.2, §6.5, Q-11, D-62, D-66).
 *
 * **Pure**, no float, `brick/math`'s `BigDecimal` only (plan Ruling 5). The
 * fixed order, which FP-237's TypeScript twin must match exactly:
 *
 * 1. `$quantity` is an int, or a string matching `^-?\d+(\.\d+)?$`. Anything
 *    else is `invalid_quantity`.
 * 2. The entered quantity is rounded to the **line unit's own** `precision`
 *    first, `RoundingMode::HalfUp` (MySQL's `ROUND()` on exact values), then
 *    rescaled to 4 decimals for storage.
 * 3. `base_quantity = ROUND(rounded quantity × unit_factor, 4)`, `HalfUp`.
 * 4. **Q-11:** if the **stock** unit's `precision` is `0` and `base_quantity`
 *    has a non-zero fraction, the result is `fractional_base_quantity` -
 *    rejected, never rounded.
 * 5. Either value outside `DECIMAL(18,4)` (more than 14 integer digits)
 *    gives `quantity_out_of_range`.
 */
final class SnapshotCalculator
{
    /**
     * @throws UnitResolutionRefused
     */
    public static function compute(
        ResolvedFactor $factor,
        string $lineUnitId,
        string $lineUnitCode,
        int $lineUnitPrecision,
        int $stockUnitPrecision,
        int|string $quantity,
    ): UnitSnapshot {
        $quantity = self::checkQuantity($quantity);

        // Step 2: round to the line unit's own precision first, then
        // rescale to the storage scale. Never the reverse order (AC-16).
        $roundedQuantity = $quantity->toScale($lineUnitPrecision, RoundingMode::HalfUp)
            ->toScale(4, RoundingMode::HalfUp);

        // Step 3: base is computed from the already-rounded quantity.
        $baseQuantity = $roundedQuantity->multipliedBy(BigDecimal::of($factor->factor))
            ->toScale(4, RoundingMode::HalfUp);

        // Step 4, Q-11: a precision-0 stock unit may never carry a
        // fractional base quantity.
        if ($stockUnitPrecision === 0 && $baseQuantity->compareTo($baseQuantity->toScale(0, RoundingMode::Down)) !== 0) {
            throw UnitResolutionRefused::fractionalBaseQuantity(
                "The resolved base quantity [{$baseQuantity}] is not a whole number, and the stock unit's precision is 0."
            );
        }

        // Step 5: DECIMAL(18,4) holds at most 14 integer digits.
        self::checkRange($roundedQuantity);
        self::checkRange($baseQuantity);

        return new UnitSnapshot(
            $lineUnitId,
            $lineUnitCode,
            $roundedQuantity->toScale(4, RoundingMode::Unnecessary)->__toString(),
            $factor->factor,
            $baseQuantity->toScale(4, RoundingMode::Unnecessary)->__toString(),
            $factor->source,
        );
    }

    private static function checkQuantity(int|string $quantity): BigDecimal
    {
        if (is_int($quantity)) {
            return BigDecimal::of($quantity);
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $quantity) !== 1) {
            throw UnitResolutionRefused::invalidQuantity("The quantity [{$quantity}] is not an int or a plain decimal string.");
        }

        try {
            return BigDecimal::of($quantity);
        } catch (MathException) {
            throw UnitResolutionRefused::invalidQuantity("The quantity [{$quantity}] is not an int or a plain decimal string.");
        }
    }

    /**
     * @throws UnitResolutionRefused
     */
    private static function checkRange(BigDecimal $value): void
    {
        $integerDigits = strlen(ltrim($value->abs()->toScale(0, RoundingMode::Down)->__toString(), '0')) ?: 1;

        if ($integerDigits > 14) {
            throw UnitResolutionRefused::quantityOutOfRange("The value [{$value}] does not fit DECIMAL(18,4).");
        }
    }
}
