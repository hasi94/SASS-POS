<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The five account types (T-032 AC-5, AC-6, AC-7).
 *
 * Owns two rules so each has one home: which side of the ledger a type
 * normally carries (AC-6) and which subtypes belong to it (AC-7).
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    /**
     * Assets and expenses carry a debit balance; liabilities, equity and
     * revenue a credit one (AC-6).
     */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue => NormalBalance::Credit,
        };
    }

    /**
     * The subtypes this type allows (AC-7), in the order the spec lists them.
     *
     * @return list<AccountSubtype>
     */
    public function subtypes(): array
    {
        return match ($this) {
            self::Asset => [
                AccountSubtype::CurrentAsset, AccountSubtype::FixedAsset, AccountSubtype::Inventory,
                AccountSubtype::Receivable, AccountSubtype::Cash, AccountSubtype::Bank,
            ],
            self::Liability => [
                AccountSubtype::CurrentLiability, AccountSubtype::Payable, AccountSubtype::Tax,
                AccountSubtype::LongTermLiability,
            ],
            self::Equity => [AccountSubtype::Capital, AccountSubtype::RetainedEarnings],
            self::Revenue => [AccountSubtype::Sales, AccountSubtype::OtherIncome],
            self::Expense => [
                AccountSubtype::Cogs, AccountSubtype::OperatingExpense, AccountSubtype::PayrollExpense,
                AccountSubtype::OtherExpense,
            ],
        };
    }
}
