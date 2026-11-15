<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

/**
 * The one way to append to a customer's credit ledger and move
 * `customers.credit_balance` (T-028 plan Ruling 2, Ruling 6;
 * `docs/database.md` §6.9).
 *
 * **The only way to change `customers.credit_balance`.** Nothing outside
 * `packages/customers/src/Ledger` may write `customer_ledger` or
 * `customers.credit_balance`/`loyalty_points`/`loyalty_tier` directly -
 * `QaCustomersSourceRulesTest` enforces that. `modules/demo` is not granted
 * this namespace's implementation either - see
 * `Flexipos\Customers\Ledger\CreditLedgerCalculator`, which stays
 * unreachable from outside `packages/customers` by the deptrac rule AC-38
 * names.
 *
 * **Callable inside the caller's own open transaction.** The tenant
 * connection nests a call made inside an already-open `transaction()` as a
 * savepoint, so a caller that rolls back its own transaction leaves nothing
 * behind from this call either.
 *
 * **Never sum `customer_ledger` yourself.** `customers.credit_balance` is
 * the maintained snapshot of that sum; `CreditLedgerCalculator::fold()`
 * exists only as this task's own test oracle, never as a second production
 * path.
 */
interface CustomerCreditLedger
{
    /**
     * @throws CreditLedgerRefused
     */
    public function record(CreditLedgerEntry $entry): CreditLedgerPosting;
}
