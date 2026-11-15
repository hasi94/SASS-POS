<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

/**
 * The closed vocabulary of `loyalty_transactions.type` (T-028 plan Ruling 2;
 * `docs/database.md` §6.9).
 *
 * One case per §6.9 entry type, exactly five. Each carries a hard sign under
 * `LoyaltyCalculator` (plan D-26, F-9) - see that class, not this enum, for
 * the sign rule itself.
 */
enum LoyaltyTransactionType: string
{
    /** Points accrued from a purchase or promotion (positive only). */
    case Earn = 'earn';

    /** Points spent against a reward (negative only). */
    case Redeem = 'redeem';

    /** A manual correction (either sign). */
    case Adjust = 'adjust';

    /** Points lapsing under a program's expiry rule (negative only). */
    case Expire = 'expire';

    /** A merge's compensating pair moving a balance between customers (either sign). */
    case Transfer = 'transfer';
}
