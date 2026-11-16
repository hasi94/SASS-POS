<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The closed vocabulary of `accounts.subtype` (T-032 AC-7).
 *
 * Which type each belongs to is stated once, by {@see AccountType::subtypes()}.
 */
enum AccountSubtype: string
{
    case CurrentAsset = 'current_asset';
    case FixedAsset = 'fixed_asset';
    case Inventory = 'inventory';
    case Receivable = 'receivable';
    case Cash = 'cash';
    case Bank = 'bank';
    case CurrentLiability = 'current_liability';
    case Payable = 'payable';
    case Tax = 'tax';
    case LongTermLiability = 'long_term_liability';
    case Capital = 'capital';
    case RetainedEarnings = 'retained_earnings';
    case Sales = 'sales';
    case OtherIncome = 'other_income';
    case Cogs = 'cogs';
    case OperatingExpense = 'operating_expense';
    case PayrollExpense = 'payroll_expense';
    case OtherExpense = 'other_expense';
}
