<?php

declare(strict_types=1);

namespace Flexipos\Sales\Registry;

use Flexipos\Contracts\Access\PermissionDeclaration;

/**
 * The permission keys this package owns (T-030 D-30, F-5c).
 *
 * **These are the first real branch-scoped keys** (R-25): Access and
 * Organization declare only company-wide ones. A branch-scoped key is asked at
 * the branch of the thing being decided, and a grant at the null scope covers
 * every branch. Sales has no routes, and the host's route sweep refuses a
 * branch-scoped key on one, so none may be attached to a route without first
 * giving the route a branch to ask about.
 *
 * The declarations are values, not rows: the existing registry sync is the only
 * writer of `permissions`. A key joins in the same change as the slice that
 * checks it.
 */
final class SalesPermissions
{
    public const APPROVALS_DECIDE = 'sales.approvals.decide';

    public const SHIFTS_RECONCILE = 'sales.shifts.reconcile';

    /** The module name every declaration here carries. */
    public const MODULE = 'sales';

    /**
     * @return list<PermissionDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new PermissionDeclaration(
                self::APPROVALS_DECIDE,
                self::MODULE,
                'approvals',
                'Approve or reject a void, refund, price override or discount at a branch.',
                true,
            ),
            new PermissionDeclaration(
                self::SHIFTS_RECONCILE,
                self::MODULE,
                'shifts',
                'Mark a closed cash shift reconciled at a branch.',
                true,
            ),
        ];
    }
}
