<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Catalog;

/**
 * Which ladder step (`docs/database.md` §6.3) produced a resolved factor
 * (T-027 plan Ruling 2, Ruling 3).
 *
 * One case per step that can actually succeed. Step 5 (exhaustion) has no
 * case, because it never produces a factor - it throws
 * {@see UnitResolutionRefused} instead.
 */
enum FactorSource: string
{
    /** Step 1: a `unit_conversions` row scoped to this product. */
    case ProductConversion = 'product_conversion';

    /** Step 2: a `unit_conversions` row with `product_id IS NULL`. */
    case GlobalConversion = 'global_conversion';

    /** Step 3: the `units.base_unit_id`/`factor` chain, walked to a common root. */
    case UnitChain = 'unit_chain';

    /** Step 4: `fromUnit` is the product's own stock unit, factor `1`. */
    case StockUnit = 'stock_unit';

    /**
     * Not a ladder step: a referencing line (a refund line) took the factor of
     * the line it refers to, in the same unit, and never re-walked the ladder
     * (T-030 D-32, `UnitResolver::snapshotInherited()`).
     */
    case Inherited = 'inherited';
}
