<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

/**
 * What the tenant database says about one branch id (T-014 AC-52).
 *
 * Three states and not two, because the two refusals that follow from them
 * are different ones: a branch that does not exist is `unknown_branch`, a
 * branch that exists and is switched off is `branch_inactive`, and a caller
 * that could only ask "is it usable" would have to guess which.
 */
enum BranchState: string
{
    /** No `branches` row holds this id (soft-deleted rows count as absent). */
    case Unknown = 'unknown';

    /** The branch exists and `is_active` is true. */
    case Active = 'active';

    /** The branch exists and has been deactivated. */
    case Inactive = 'inactive';
}
