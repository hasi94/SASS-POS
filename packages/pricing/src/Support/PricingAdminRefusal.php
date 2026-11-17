<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Flexipos\Contracts\Pricing\PricingRefusal;

/**
 * The closed refusal vocabulary of the pricing CRUD slices (T-029 plan
 * Ruling 9). It is not {@see PricingRefusal}, which
 * is the calculation engine's own: a write refused here never reaches the
 * engine, and a quote refused there never wrote anything. No HTTP surface
 * exists yet, so this carries no status.
 */
enum PricingAdminRefusal: string
{
    /** The submission is malformed, incomplete, or names a field that may not be sent. */
    case Validation = 'validation';

    /** No such row in this tenant, or a well-formed id of another tenant's. */
    case NotFound = 'not_found';

    /** A unique key already holds the submitted value. */
    case Duplicate = 'duplicate';

    /** The row is still referenced by something live. */
    case InUse = 'in_use';

    /** The date range collides with another row's range (AC-6). */
    case Overlap = 'overlap';

    /** The field is maintained by the system and may not be supplied (AC-19 `used_count`). */
    case Maintained = 'maintained';

    /** The unit is not one the product's unit ladder can reach (AC-13). */
    case UnresolvableUnit = 'unresolvable_unit';

    /** A `buy_x_get_y` discount names no product (AC-19). */
    case MissingTarget = 'missing_target';

    /** A `fixed` tax asked to compound (AC-5, F-9). */
    case FixedCannotCompound = 'fixed_cannot_compound';
}
