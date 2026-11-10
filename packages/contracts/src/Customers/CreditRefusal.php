<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

/**
 * The closed refusal vocabulary of `CustomerCreditLedger` (T-028 plan
 * Ruling 2).
 *
 * One case per reason the ledger writer or `CreditLedgerCalculator` can
 * refuse. Every case is reachable from the published contract alone;
 * nothing here names a table or a column.
 */
enum CreditRefusal: string
{
    /** The named customer does not exist. */
    case UnknownCustomer = 'unknown_customer';

    /** The customer is soft-deleted or merged into another customer (D-30). */
    case CustomerNotLive = 'customer_not_live';

    /** `credit_sale`/`debit_note` against a customer whose `status` is not `active` (D-30). */
    case CustomerNotActive = 'customer_not_active';

    /** `debit` or `credit` is not an int or a `^\d+(\.\d{1,4})?$` string (Ruling 3.1). */
    case InvalidAmount = 'invalid_amount';

    /** Both `debit` and `credit` are zero. */
    case ZeroAmount = 'zero_amount';

    /** Both `debit` and `credit` are non-zero. */
    case BothSides = 'both_sides';

    /** The entry type's hard side (D-26, F-9) was violated. */
    case WrongSide = 'wrong_side';

    /** An operand or the resulting balance does not fit `DECIMAL(18,4)` (Ruling 3.3). */
    case OutOfRange = 'out_of_range';

    /** The customer's `credit_allowed` is false (D-12, D-27). */
    case CreditNotAllowed = 'credit_not_allowed';

    /** The resulting balance would exceed the customer's `credit_limit` (D-12). */
    case OverLimit = 'over_limit';

    /** The customer has no `currency` set (D-25). */
    case CurrencyRequired = 'currency_required';

    /** The entry's currency does not match the customer's (D-25). */
    case CurrencyMismatch = 'currency_mismatch';

    /** The named branch does not exist. */
    case UnknownBranch = 'unknown_branch';

    /** The named employee does not exist. */
    case UnknownEmployee = 'unknown_employee';

    /** The replay key matches a stored row with a different payload (D-21, F-4). */
    case ReplayConflict = 'replay_conflict';

    /** `reference_type` names one of the internal-only replay keys reserved for `MergeCustomers`/`CreateCustomer` (R-1). */
    case ReservedReferenceType = 'reserved_reference_type';
}
