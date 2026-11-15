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

    /**
     * The snapshot for a line that **refers to another line** - a refund line
     * (T-030 D-32, F-7; `docs/database.md` §6.5). The ladder is never walked.
     *
     * Three outcomes, decided in this order:
     *
     * 1. `$unitId` is the product's own **stock** unit: the factor is
     *    `1.000000`, source {@see FactorSource::StockUnit}, whatever
     *    `$referencedUnitFactor` says. The resolver reads the stock unit
     *    itself and never trusts a caller's.
     * 2. `$unitId` is `$referencedUnitId`: the referenced line's own
     *    `unit_factor`, source {@see FactorSource::Inherited}. The ladder
     *    may have moved since the sale, and the refund must not follow it.
     * 3. Any other unit is refused {@see UnitRefusal::InheritedUnitMismatch}.
     *
     * `$referencedUnitFactor` is what the caller read off the referenced line.
     * A database driver may hand it back as `"24"` rather than `"24.000000"`,
     * so it is canonicalised here, and a value that cannot be a factor is a
     * named refusal ({@see UnitRefusal::FactorBelowOne},
     * {@see UnitRefusal::FactorOutOfRange}, {@see UnitRefusal::InexactFactor}),
     * never an `InvalidArgumentException`. The quantity is rounded to the line
     * unit's own precision first, exactly as {@see self::snapshot()} does.
     *
     * @throws UnitResolutionRefused
     */
    public function snapshotInherited(
        string $productId,
        string $referencedUnitId,
        string $referencedUnitFactor,
        string $unitId,
        int|string $quantity,
    ): UnitSnapshot;
}
