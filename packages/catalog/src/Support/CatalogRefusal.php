<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Support;

/**
 * The closed refusal vocabulary of the catalog package (T-027 plan section
 * 3). No HTTP surface exists yet (Ruling 10), so this carries no status -
 * that arrives with FP-240's API task.
 */
enum CatalogRefusal: string
{
    /** The submission is malformed, incomplete, or names a field that may not change. */
    case Validation = 'validation';

    /** No such row in this tenant, or a well-formed id of another tenant's. */
    case NotFound = 'not_found';

    /** A unique column already holds the submitted value. */
    case Duplicate = 'duplicate';

    /** The row is still referenced by something live. */
    case InUse = 'in_use';

    /** A column that may never change once set was sent with a different value. */
    case Immutable = 'immutable';

    /** The change would close a cycle. */
    case Cycle = 'cycle';

    /** The change would push a row past a depth or length bound. */
    case TooDeep = 'too_deep';

    /** Two units referenced together do not share a dimension. */
    case DimensionMismatch = 'dimension_mismatch';

    /** A tax group id names no group (T-029 AC-3). */
    case UnknownTaxGroup = 'unknown_tax_group';

    /** A tax group id names a group that has been retired (T-029 AC-3). */
    case InactiveTaxGroup = 'inactive_tax_group';

    /** No tax group was named and the tenant has no active default to use (T-029 AC-3). */
    case NoDefaultTaxGroup = 'no_default_tax_group';
}
