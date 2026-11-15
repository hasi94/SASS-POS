<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

/**
 * The one way to append to a customer's loyalty ledger and move
 * `customers.loyalty_points`/`loyalty_tier` (T-028 plan Ruling 2, Ruling 6;
 * `docs/database.md` §6.9).
 *
 * **The only way to change `customers.loyalty_points`/`loyalty_tier`.**
 * Nothing outside `packages/customers/src/Loyalty` may write
 * `loyalty_transactions` or either maintained column directly -
 * `QaCustomersSourceRulesTest` enforces that, the same shape
 * `CustomerCreditLedger`'s own docblock states.
 *
 * **Callable inside the caller's own open transaction**, and never a second
 * production path for the balance - both the identical rules
 * `CustomerCreditLedger` states.
 */
interface LoyaltyLedger
{
    /**
     * @throws LoyaltyLedgerRefused
     */
    public function record(LoyaltyTransaction $tx): LoyaltyPosting;
}
