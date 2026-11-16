<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * What an account assigned to a product, category or customer must be
 * (T-032 AC-56).
 */
enum AccountKind: string
{
    /** `income_account_id`: a revenue account. */
    case Income = 'income';

    /** `cogs_account_id`: an expense account of subtype cogs. */
    case Cogs = 'cogs';

    /** `inventory_account_id`: an asset account of subtype inventory. */
    case Inventory = 'inventory';

    /** `receivable_account_id`: an asset account of subtype receivable. */
    case Receivable = 'receivable';

    /**
     * Whether the account's type and subtype fit this kind (AC-56).
     */
    public function accepts(AccountSnapshot $account): bool
    {
        return match ($this) {
            self::Income => $account->type === AccountType::Revenue,
            self::Cogs => $account->type === AccountType::Expense
                && $account->subtype === AccountSubtype::Cogs,
            self::Inventory => $account->type === AccountType::Asset
                && $account->subtype === AccountSubtype::Inventory,
            self::Receivable => $account->type === AccountType::Asset
                && $account->subtype === AccountSubtype::Receivable,
        };
    }
}
