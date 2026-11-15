<?php

declare(strict_types=1);

namespace Flexipos\Sales\Exceptions;

/**
 * The closed refusal vocabulary of the sales slices (T-030 plan, "Refusal
 * vocabulary", and Ruling 8's amendment R-40).
 *
 * A refusal that comes from another package (the pricing engine, the unit
 * resolver, the credit ledger, the document numbering) is carried through under
 * its own value as the {@see SalesRefused::$cause}; the cases here are the codes
 * a caller of Sales sees. No HTTP surface exists yet, so this carries no status.
 */
enum SalesRefusal: string
{
    // General.
    case Validation = 'validation';
    case NotFound = 'not_found';
    case Duplicate = 'duplicate';
    case InUse = 'in_use';
    case OutOfRange = 'out_of_range';
    case InvalidTransition = 'invalid_transition';
    case NumberingRefused = 'numbering_refused';

    // Shifts.
    case NoOpenShift = 'no_open_shift';
    case ShiftAlreadyOpen = 'shift_already_open';
    case ShiftNotOpen = 'shift_not_open';
    case ShiftHasOpenSales = 'shift_has_open_sales';
    case ShiftClosed = 'shift_closed';

    // Cart.
    case SaleNotEditable = 'sale_not_editable';
    case UnitUnresolvable = 'unit_unresolvable';
    case UnresolvablePrice = 'unresolvable_price';
    case PriceOverrideNotAllowed = 'price_override_not_allowed';
    case DiscountWithOverride = 'discount_with_override';
    case EmptySale = 'empty_sale';
    case ProductNotSellable = 'product_not_sellable';

    // Tenders.
    case CustomerRequired = 'customer_required';
    case CreditRefused = 'credit_refused';
    case MethodInactive = 'method_inactive';
    case UnsupportedMethodType = 'unsupported_method_type';
    case ReferenceRequired = 'reference_required';
    case ExceedsBalance = 'exceeds_balance';
    case PartialNotAllowed = 'partial_not_allowed';
    case RefundNotAllowed = 'refund_not_allowed';
    case BalanceDue = 'balance_due';
    case TenderNotRemovable = 'tender_not_removable';

    // Approvals.
    case ApprovalRequired = 'approval_required';
    case ApprovalNotApproved = 'approval_not_approved';
    case ApprovalAlreadyUsed = 'approval_already_used';
    case ApprovalMismatch = 'approval_mismatch';
    case ApprovalNotPending = 'approval_not_pending';
    case SelfApprovalNotAllowed = 'self_approval_not_allowed';
    case PinInvalid = 'pin_invalid';
    case NotAuthorised = 'not_authorised';

    // Corrections and replay.
    case SaleNotVoidable = 'sale_not_voidable';
    case NotRefundable = 'not_refundable';
    case OverRefund = 'over_refund';
    case FullyRefunded = 'fully_refunded';
    case InheritedUnitMismatch = 'inherited_unit_mismatch';
    case ReplayConflict = 'replay_conflict';
}
