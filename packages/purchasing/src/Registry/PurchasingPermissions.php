<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Registry;

use Flexipos\Contracts\Access\PermissionDeclaration;

/**
 * The permission keys this package owns (T-034 Ruling 9, D-26, AC-95).
 *
 * **Eight keys, two scopes.** The supplier keys are tenant-wide: a supplier
 * belongs to no branch, so they are asked with a null branch and are not
 * branch-scoped (AC-18). Every document key is branch-scoped and is asked at the
 * **stored** branch of the document, never a caller-supplied one (AC-85 (d));
 * a grant at the null scope covers every branch.
 *
 * The declarations are values, not rows: the existing registry sync is the only
 * writer of `permissions`. Purchasing has no routes, and the host's route sweep
 * refuses a branch-scoped key on one.
 */
final class PurchasingPermissions
{
    public const SUPPLIERS_VIEW = 'purchasing.suppliers.view';

    public const SUPPLIERS_MANAGE = 'purchasing.suppliers.manage';

    public const REQUESTS_VIEW = 'purchasing.requests.view';

    public const REQUESTS_MANAGE = 'purchasing.requests.manage';

    public const ORDERS_VIEW = 'purchasing.orders.view';

    public const ORDERS_MANAGE = 'purchasing.orders.manage';

    public const ORDERS_CLOSE = 'purchasing.orders.close';

    public const ORDERS_REOPEN = 'purchasing.orders.reopen';

    /** The module name every declaration here carries. */
    public const MODULE = 'purchasing';

    /**
     * @return list<PermissionDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new PermissionDeclaration(self::SUPPLIERS_VIEW, self::MODULE, 'suppliers', 'View suppliers.', false),
            new PermissionDeclaration(self::SUPPLIERS_MANAGE, self::MODULE, 'suppliers', 'Create, change, deactivate and delete suppliers, and see their bank details.', false),
            new PermissionDeclaration(self::REQUESTS_VIEW, self::MODULE, 'requests', 'View purchase requests at a branch.', true),
            new PermissionDeclaration(self::REQUESTS_MANAGE, self::MODULE, 'requests', 'Raise, change, submit, cancel and convert purchase requests at a branch.', true),
            new PermissionDeclaration(self::ORDERS_VIEW, self::MODULE, 'orders', 'View purchase orders at a branch.', true),
            new PermissionDeclaration(self::ORDERS_MANAGE, self::MODULE, 'orders', 'Raise, change, submit and cancel purchase orders at a branch.', true),
            new PermissionDeclaration(self::ORDERS_CLOSE, self::MODULE, 'orders', 'Close a received or partly received purchase order at a branch.', true),
            new PermissionDeclaration(self::ORDERS_REOPEN, self::MODULE, 'orders', 'Reopen an approved purchase order for editing at a branch.', true),
        ];
    }
}
