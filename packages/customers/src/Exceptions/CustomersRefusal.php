<?php

declare(strict_types=1);

namespace Flexipos\Customers\Exceptions;

/**
 * The closed refusal vocabulary `CustomersRefused` carries (T-028 plan
 * section 3), covering every slice's own refusals - not a ledger write,
 * which refuses through the published contract's own
 * `CreditRefusal`/`LoyaltyRefusal` instead.
 */
enum CustomersRefusal: string
{
    case Validation = 'validation';
    case NotFound = 'not_found';
    case Duplicate = 'duplicate';
    case InUse = 'in_use';
    case Stale = 'stale';
    case Maintained = 'maintained';
    case BalanceOutstanding = 'balance_outstanding';
    case SelfMerge = 'self_merge';
    case AlreadyMerged = 'already_merged';
    case NotLive = 'not_live';
    case CurrencyMismatch = 'currency_mismatch';
}
