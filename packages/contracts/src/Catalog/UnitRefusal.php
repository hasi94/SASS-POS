<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Catalog;

/**
 * The closed refusal vocabulary of `UnitResolver` (T-027 plan Ruling 2).
 *
 * One case per reason the ladder (`docs/database.md` §6.3) or the snapshot
 * calculator (§3.2, Q-11) can refuse. Every case is reachable from the
 * published contract alone; nothing here names a table or a column.
 */
enum UnitRefusal: string
{
    /** The named product does not exist (step 0 of the ladder). */
    case UnknownProduct = 'unknown_product';

    /** `fromUnit` (or the stock unit) does not exist. */
    case UnknownUnit = 'unknown_unit';

    /** `fromUnit` and the product's stock unit do not share `units.dimension` (AC-10). */
    case CrossDimension = 'cross_dimension';

    /** No product-scoped row, no global row, and no base-unit chain reaches the stock unit (AC-15). */
    case NoConversionPath = 'no_conversion_path';

    /** A resolved factor would be below 1 (AC-9, Q-9). */
    case FactorBelowOne = 'factor_below_one';

    /** The base-unit chain quotient has no exact 6-decimal form (plan Ruling 3, step 3). */
    case InexactFactor = 'inexact_factor';

    /** A resolved factor exceeds `DECIMAL(18,6)`. */
    case FactorOutOfRange = 'factor_out_of_range';

    /** The submitted quantity is not an int or a `^-?\d+(\.\d+)?$` string (plan Ruling 4). */
    case InvalidQuantity = 'invalid_quantity';

    /** `quantity` or `base_quantity` would not fit `DECIMAL(18,4)`. */
    case QuantityOutOfRange = 'quantity_out_of_range';

    /** The stock unit's `precision` is 0 and the computed `base_quantity` has a non-zero fraction (AC-12, Q-11). */
    case FractionalBaseQuantity = 'fractional_base_quantity';
}
