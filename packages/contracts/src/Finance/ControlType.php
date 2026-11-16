<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The closed vocabulary of `accounts.control_type` (T-032 plan Ruling 2;
 * `docs/database.md` section 6.10).
 *
 * Eleven cases: `none` and ten control types. Five of them may have only one
 * live holder in a tenant (D-3): the five a posting rule falls back on with
 * exactly one answer. Cash, bank, inventory and the two tax types legitimately
 * vary by till, bank account, location and per-product override.
 *
 * **This list has two homes**, this enum and the stored generated
 * `accounts.control_marker` expression in the `create_accounts_table`
 * migration, because a migration may not import this package (the
 * source rules allow `Illuminate\` and the kernel's schema helpers only).
 * `FinanceTenantSchemaTest` parses the migration and asserts the two are
 * equal, so they cannot drift.
 *
 * What type and subtype each control type demands (AC-16) is
 * {@see self::requires()}.
 */
enum ControlType: string
{
    /** An ordinary account: no posting rule falls back on it. */
    case None = 'none';

    /** The cash drawer account; repeatable. */
    case Cash = 'cash';

    /** A bank account; repeatable. */
    case Bank = 'bank';

    /** Stock on hand; repeatable. */
    case Inventory = 'inventory';

    /** Accounts receivable; single. */
    case Ar = 'ar';

    /** Accounts payable; single. */
    case Ap = 'ap';

    /** Tax collected and owed; repeatable. */
    case TaxPayable = 'tax_payable';

    /** Tax paid and reclaimable; repeatable. */
    case TaxReceivable = 'tax_receivable';

    /** Goods received not invoiced; single. */
    case Grni = 'grni';

    /** Wages earned and not yet paid; single. */
    case PayrollPayable = 'payroll_payable';

    /** The year-end roll-up account; single. */
    case RetainedEarnings = 'retained_earnings';

    /**
     * Whether a tenant may hold only one live account of this type (D-3).
     */
    public function isSingle(): bool
    {
        return in_array($this, self::singles(), true);
    }

    /**
     * The type and subtype an account of this control type must have (AC-16),
     * or null for `none`, which demands nothing.
     *
     * @return array{type: AccountType, subtype: AccountSubtype}|null
     */
    public function requires(): ?array
    {
        return match ($this) {
            self::None => null,
            self::Cash => ['type' => AccountType::Asset, 'subtype' => AccountSubtype::Cash],
            self::Bank => ['type' => AccountType::Asset, 'subtype' => AccountSubtype::Bank],
            self::Inventory => ['type' => AccountType::Asset, 'subtype' => AccountSubtype::Inventory],
            self::Ar => ['type' => AccountType::Asset, 'subtype' => AccountSubtype::Receivable],
            self::Ap => ['type' => AccountType::Liability, 'subtype' => AccountSubtype::Payable],
            self::TaxPayable => ['type' => AccountType::Liability, 'subtype' => AccountSubtype::Tax],
            self::TaxReceivable => ['type' => AccountType::Asset, 'subtype' => AccountSubtype::CurrentAsset],
            self::Grni => ['type' => AccountType::Liability, 'subtype' => AccountSubtype::CurrentLiability],
            self::PayrollPayable => ['type' => AccountType::Liability, 'subtype' => AccountSubtype::CurrentLiability],
            self::RetainedEarnings => ['type' => AccountType::Equity, 'subtype' => AccountSubtype::RetainedEarnings],
        };
    }

    /**
     * The control types that may have only one live holder, in the order the
     * `control_marker` expression lists them.
     *
     * @return list<self>
     */
    public static function singles(): array
    {
        return [self::Ar, self::Ap, self::Grni, self::PayrollPayable, self::RetainedEarnings];
    }
}
