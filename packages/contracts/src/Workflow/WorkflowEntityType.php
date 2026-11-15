<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

/**
 * The full `workflow_definitions.entity_type` vocabulary (`docs/database.md`
 * section 6.15), in the order that document gives (T-020 AC-1, Ruling 2).
 *
 * Shipped whole, including the ten entity types no module wires in yet
 * (D-8): a future module naming its own value is additive, never an expand
 * migration onto a wrong shape. `custom` is reserved for the one
 * proof-of-pipeline fixture this task ships (D-11, AC-27, AC-28) and for a
 * caller's own test harness; no module's own source ships a real
 * definition against it.
 */
enum WorkflowEntityType: string
{
    case Expense = 'expense';
    case PurchaseRequest = 'purchase_request';
    case PurchaseOrder = 'purchase_order';
    case SupplierInvoice = 'supplier_invoice';
    case StockAdjustment = 'stock_adjustment';
    case StockTransfer = 'stock_transfer';
    case LeaveRequest = 'leave_request';
    case Overtime = 'overtime';
    case PayrollRun = 'payroll_run';
    case CommissionPeriod = 'commission_period';
    case Discount = 'discount';
    case SaleVoid = 'sale_void';
    case Refund = 'refund';
    case Custom = 'custom';
}
