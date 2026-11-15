<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

/**
 * The closed vocabulary of `customer_ledger.type` (T-028 plan Ruling 2;
 * `docs/database.md` §6.9).
 *
 * One case per §6.9 entry type, exactly eight. Each carries a hard
 * debit/credit side under `CreditLedgerCalculator` (plan D-26, F-9) - see
 * that class, not this enum, for the side rule itself, which belongs to the
 * customers package and not to this published contract.
 */
enum CreditEntryType: string
{
    /** A customer-initiated payment against the balance (credit side). */
    case Payment = 'payment';

    /** A sale extended on credit (debit side). */
    case CreditSale = 'credit_sale';

    /** A refund of an overpayment or a returned sale (credit side). */
    case Refund = 'refund';

    /** A documented credit correction reducing what is owed (credit side). */
    case CreditNote = 'credit_note';

    /** A documented debit correction increasing what is owed (debit side). */
    case DebitNote = 'debit_note';

    /** A one-time opening balance set at customer creation (either side). */
    case Opening = 'opening';

    /** A manual correction, including a merge's compensating pair (either side). */
    case Adjustment = 'adjustment';

    /** An uncollectable balance written off (credit side). */
    case WriteOff = 'write_off';
}
