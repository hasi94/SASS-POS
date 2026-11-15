<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tenders;

use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Contracts\Customers\CreditLedgerEntry;
use Flexipos\Contracts\Customers\CreditLedgerRefused;
use Flexipos\Contracts\Customers\CreditRefusal;
use Flexipos\Contracts\Customers\CustomerCreditLedger;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Support\Money;

/**
 * The one place Sales talks to the customer credit ledger (T-030 AC-14, AC-22,
 * AC-30, AC-48, AC-49; D-29, D-66, D-68; F-2 to F-4; R-4, R-20).
 *
 * **One posting per tender, keyed on the tender.** `reference_type` is
 * `sale_tender` and `reference_id` is the tender's own id, never the sale's: a
 * sale with two credit tenders posts two entries that replay independently, and
 * a void or refund posts under the id of the mirror or refund tender it writes.
 *
 * **Amounts are positive canonical strings; the entry's type picks the side.** A
 * credit sale is a debit of the tender's amount; a credit note (a void) and a
 * refund are credits of the absolute value of the mirror's or the refund tender's
 * negative amount ({@see Money::abs()}).
 *
 * **A replayed posting is refused, never accepted** (D-66, R-4). Each caller makes
 * the first and only post for its key inside a transaction that cannot repeat, so
 * the ledger reporting `replayed` means a row was already there (a manual entry
 * pre-seeding a tender's id, say), and completing without a debit of its own would
 * be wrong. The refusal is `credit_refused` carrying `replay_conflict`, and the
 * caller's transaction rolls back.
 *
 * The ledger writes its own audit row and takes the customer row lock, so a call
 * is lock 6 in Ruling 7's order, after the sale, the approval and the discounts,
 * and before the document number. Call it inside the writing transaction.
 */
final class CreditPosting
{
    public function __construct(private readonly CustomerCreditLedger $ledger) {}

    /**
     * Debit the customer for a credit tender at completion.
     *
     * @throws SalesRefused credit_refused
     */
    public function creditSale(object $sale, object $tender, string $actorId): void
    {
        $this->post(CreditEntryType::CreditSale, $sale, strtoupper((string) $tender->id), Money::abs(Money::of($tender->amount)), Money::ZERO, $actorId);
    }

    /**
     * Compensate a credit tender that a void has mirrored.
     *
     * @throws SalesRefused credit_refused
     */
    public function creditNote(object $sale, string $mirrorTenderId, string $mirrorAmount, string $actorId): void
    {
        $this->post(CreditEntryType::CreditNote, $sale, strtoupper($mirrorTenderId), Money::ZERO, Money::abs($mirrorAmount), $actorId);
    }

    /**
     * Pay a customer back onto their credit balance for a refund tender.
     *
     * @throws SalesRefused credit_refused
     */
    public function refund(object $sale, string $refundTenderId, string $refundAmount, string $actorId): void
    {
        $this->post(CreditEntryType::Refund, $sale, strtoupper($refundTenderId), Money::ZERO, Money::abs($refundAmount), $actorId);
    }

    private function post(CreditEntryType $type, object $sale, string $tenderId, string $debit, string $credit, string $actorId): void
    {
        if ($sale->customer_id === null) {
            throw SalesRefused::because(SalesRefusal::CustomerRequired, 'A credit tender needs a sale with a customer.');
        }

        try {
            $posting = $this->ledger->record(new CreditLedgerEntry(
                (string) $sale->customer_id,
                $type,
                'sale_tender',
                $tenderId,
                $debit,
                $credit,
                (string) $sale->currency,
                (string) $sale->branch_id,
                (string) $sale->employee_id,
                null,
                $actorId,
            ));
        } catch (CreditLedgerRefused $refused) {
            throw SalesRefused::fromCredit($refused);
        }

        if ($posting->replayed) {
            throw SalesRefused::because(
                SalesRefusal::CreditRefused,
                "The credit ledger already holds an entry for tender [{$tenderId}] as a [{$type->value}]; nothing was posted.",
                CreditRefusal::ReplayConflict,
            );
        }
    }
}
