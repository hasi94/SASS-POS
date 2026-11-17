<?php

declare(strict_types=1);

namespace Flexipos\Finance\Registry;

use Flexipos\Contracts\Access\PermissionDeclaration;

/**
 * The permission keys this package owns: seven from T-032 (AC-64, D-24, plan
 * Ruling 5) and three journal keys from T-033 (AC-72).
 *
 * **All of them are `is_branch_scoped = false`.** The chart and the calendar
 * belong to the tenant, not to a branch, and every handler asks the resolver
 * at a null scope, so a branch-scoped key would refuse every request.
 *
 * They are the first three-segment keys the platform declares. The
 * `finance.` namespace is deliberate: FP-261 to FP-268 add journal, AR, AP
 * and bank keys under one module, and a bare `accounts.*` would sit next to
 * FP-263's `financial_accounts`.
 *
 * The declarations are values, not rows: the registry sync is the only
 * writer of `permissions`.
 */
final class FinancePermissions
{
    public const ACCOUNTS_VIEW = 'finance.accounts.view';

    public const ACCOUNTS_MANAGE = 'finance.accounts.manage';

    public const PERIODS_VIEW = 'finance.periods.view';

    public const PERIODS_MANAGE = 'finance.periods.manage';

    public const PERIODS_CLOSE = 'finance.periods.close';

    public const PERIODS_REOPEN = 'finance.periods.reopen';

    public const PERIODS_POST_ADJUSTMENT = 'finance.periods.post_adjustment';

    public const JOURNAL_VIEW = 'finance.journal.view';

    public const JOURNAL_POST_MANUAL = 'finance.journal.post_manual';

    public const JOURNAL_REVERSE = 'finance.journal.reverse';

    /** The module name every declaration here carries. */
    public const MODULE = 'finance';

    /**
     * Every key, in declaration order.
     *
     * @var list<string>
     */
    public const KEYS = [
        self::ACCOUNTS_VIEW,
        self::ACCOUNTS_MANAGE,
        self::PERIODS_VIEW,
        self::PERIODS_MANAGE,
        self::PERIODS_CLOSE,
        self::PERIODS_REOPEN,
        self::PERIODS_POST_ADJUSTMENT,
        self::JOURNAL_VIEW,
        self::JOURNAL_POST_MANUAL,
        self::JOURNAL_REVERSE,
    ];

    /**
     * @return list<PermissionDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new PermissionDeclaration(self::ACCOUNTS_VIEW, self::MODULE, 'accounts', 'List and read the chart of accounts.', false),
            new PermissionDeclaration(self::ACCOUNTS_MANAGE, self::MODULE, 'accounts', 'Create, update, re-parent, deactivate, reactivate and delete accounts.', false),
            new PermissionDeclaration(self::PERIODS_VIEW, self::MODULE, 'periods', 'List fiscal years and periods, and look a period up by date.', false),
            new PermissionDeclaration(self::PERIODS_MANAGE, self::MODULE, 'periods', 'Create, rename and delete fiscal years and rename periods.', false),
            new PermissionDeclaration(self::PERIODS_CLOSE, self::MODULE, 'periods', 'Soft-close and close fiscal periods, and close a fiscal year.', false),
            new PermissionDeclaration(self::PERIODS_REOPEN, self::MODULE, 'periods', 'Reopen closed fiscal periods and fiscal years.', false),
            new PermissionDeclaration(self::PERIODS_POST_ADJUSTMENT, self::MODULE, 'periods', 'Post an adjustment into a soft-closed period.', false),
            new PermissionDeclaration(self::JOURNAL_VIEW, self::MODULE, 'journal', 'List and read journal entries.', false),
            new PermissionDeclaration(self::JOURNAL_POST_MANUAL, self::MODULE, 'journal', 'Post a manual journal entry.', false),
            new PermissionDeclaration(self::JOURNAL_REVERSE, self::MODULE, 'journal', 'Reverse a posted journal entry.', false),
        ];
    }

    private function __construct() {}
}
