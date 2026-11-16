<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * What the tenant database says about one tax group id (AC-3, D-21a).
 *
 * Three states and not two, the `BranchState` shape: a group that does not
 * exist is `unknown_tax_group`, a group that exists and is retired is
 * `inactive_tax_group`, and a caller that could only ask "is it usable" would
 * have to guess which.
 */
enum TaxGroupState: string
{
    /** No `tax_groups` row holds this id. */
    case Unknown = 'unknown';

    /** The group exists and `is_active` is true. */
    case Active = 'active';

    /** The group exists and has been deactivated. */
    case Inactive = 'inactive';
}
