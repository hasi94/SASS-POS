<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Catalog;

/**
 * The one unit-conversion ladder, and the one way to snapshot a document
 * line's `[UOM]` quad (T-027 plan Ruling 2; `docs/database.md` §6.3, §3.2).
 *
 * **One ladder, one caller.** No module converts a quantity any other way,
 * and `unit_factor` on any line must come from here - never read directly
 * off `unit_conversions` or `units.factor`, and never a literal `1`. The
 * boundary check `QaCatalogSourceRulesTest` enforces the second half: no
 * file outside `packages/catalog/src/Units` may read either table's columns
 * or multiply by a factor column.
 *
 * `modules/demo` is not granted this namespace either - see
 * `Flexipos\Catalog\Units\UnitLadder`, which stays unreachable from outside
 * `packages/catalog` by the same deptrac rule.
 */
interface UnitResolver
{
    /**
     * Resolve the factor that converts one unit of `$fromUnitId` into the
     * product's own stock unit, following the five-step ladder in order.
     *
     * @throws UnitResolutionRefused
     */
    public function resolve(string $productId, string $fromUnitId): ResolvedFactor;

    /**
     * Resolve the factor, then round `$quantity` to the line unit's own
     * precision and compute the base quantity from the already-rounded
     * value - never the reverse order (AC-16).
     *
     * @throws UnitResolutionRefused
     */
    public function snapshot(string $productId, string $unitId, int|string $quantity): UnitSnapshot;
}
