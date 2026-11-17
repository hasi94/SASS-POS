<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The closed list of what an entry can be the accounting of (T-033 D-7).
 *
 * `reversal` is the stored type of a reversal entry and is created only by
 * `PostingService::reverse`; `post` refuses it (AC-6a, D-32). `manual` and
 * `opening` are the two types that may carry no `source_id` (AC-7).
 */
enum JournalSourceType: string
{
    case Manual = 'manual';
    case Opening = 'opening';
    case Sale = 'sale';
    case SaleRefund = 'sale_refund';
    case SaleVoid = 'sale_void';
    case Grn = 'grn';
    case PurchaseReturn = 'purchase_return';
    case SupplierInvoice = 'supplier_invoice';
    case SupplierPayment = 'supplier_payment';
    case CustomerPayment = 'customer_payment';
    case Expense = 'expense';
    case ExpensePayment = 'expense_payment';
    case PayrollRun = 'payroll_run';
    case PayrollPayment = 'payroll_payment';
    case CommissionPayout = 'commission_payout';
    case StockAdjustment = 'stock_adjustment';
    case StockTransfer = 'stock_transfer';
    case StockCount = 'stock_count';
    case StockProduction = 'stock_production';
    case CashMovement = 'cash_movement';
    case BankTransaction = 'bank_transaction';
    case Revaluation = 'revaluation';
    case PeriodClose = 'period_close';
    case YearClose = 'year_close';
    case Reversal = 'reversal';
    case Plugin = 'plugin';
}
