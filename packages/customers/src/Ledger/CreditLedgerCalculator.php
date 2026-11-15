<?php

declare(strict_types=1);

namespace Flexipos\Customers\Ledger;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Contracts\Customers\CreditLedgerRefused;
use Flexipos\Customers\Support\Decimal;
use InvalidArgumentException;

/**
 * The credit ledger arithmetic, pure (T-028 plan Ruling 4, Ruling 3; AC-23,
 * AC-24, AC-41).
 *
 * **Pure.** No connection, no lock, no row - `CreditLedgerWriter` supplies
 * the locked row's own values as a `CreditPosition` and calls this with
 * them. Every check runs in a **fixed order**, so a test can pin which
 * reason comes first when more than one would apply:
 *
 * 1. parse `debit` and `credit`, both unsigned (Ruling 3.1), and range-check
 *    each parsed operand (Ruling 3.3);
 * 2. both zero gives `zero_amount`; both non-zero gives `both_sides`;
 * 3. the entry type's hard side (D-26, F-9) gives `wrong_side` on a
 *    violation;
 * 4. `new = balance + debit - credit`, then the range check;
 * 5. only for `credit_sale` and `debit_note`, in this order: not `active`
 *    gives `customer_not_active`; not `creditAllowed` gives
 *    `credit_not_allowed`; `new > creditLimit` gives `over_limit` - tested
 *    on the **resulting** balance, never the amount alone.
 *
 * Never rounds (Ruling 3.2): every operand already has a scale of 4 or
 * less, so `balance + debit - credit` is always exact.
 */
final class CreditLedgerCalculator
{
    /**
     * @throws CreditLedgerRefused
     */
    public static function apply(CreditPosition $position, CreditEntryType $type, int|string $debit, int|string $credit): CreditOutcome
    {
        $debitAmount = Decimal::parseUnsigned($debit);
        $creditAmount = Decimal::parseUnsigned($credit);

        if ($debitAmount === null) {
            throw CreditLedgerRefused::invalidAmount("The debit amount [{$debit}] is not an int or a DECIMAL(18,4) string.");
        }

        if ($creditAmount === null) {
            throw CreditLedgerRefused::invalidAmount("The credit amount [{$credit}] is not an int or a DECIMAL(18,4) string.");
        }

        if (! Decimal::fitsEighteenFour($debitAmount)) {
            throw CreditLedgerRefused::outOfRange("The debit amount [{$debit}] does not fit DECIMAL(18,4).");
        }

        if (! Decimal::fitsEighteenFour($creditAmount)) {
            throw CreditLedgerRefused::outOfRange("The credit amount [{$credit}] does not fit DECIMAL(18,4).");
        }

        $debitZero = $debitAmount->isZero();
        $creditZero = $creditAmount->isZero();

        if ($debitZero && $creditZero) {
            throw CreditLedgerRefused::zeroAmount('Both debit and credit are zero.');
        }

        if (! $debitZero && ! $creditZero) {
            throw CreditLedgerRefused::bothSides('Both debit and credit are non-zero.');
        }

        $side = EntrySide::for($type);
        $enteredSide = $debitZero ? EntrySide::CreditOnly : EntrySide::DebitOnly;

        if ($side !== EntrySide::Either && $side !== $enteredSide) {
            throw CreditLedgerRefused::wrongSide("The entry type [{$type->value}] does not allow the ".($enteredSide === EntrySide::DebitOnly ? 'debit' : 'credit').' side.');
        }

        $new = BigDecimal::of($position->balance)->plus($debitAmount)->minus($creditAmount);

        if (! Decimal::fitsEighteenFour($new)) {
            throw CreditLedgerRefused::outOfRange("The resulting balance [{$new}] does not fit DECIMAL(18,4).");
        }

        if ($type === CreditEntryType::CreditSale || $type === CreditEntryType::DebitNote) {
            if (! $position->active) {
                throw CreditLedgerRefused::customerNotActive('The customer is not active.');
            }

            if (! $position->creditAllowed) {
                throw CreditLedgerRefused::creditNotAllowed('The customer is not allowed credit.');
            }

            if ($new->compareTo(BigDecimal::of($position->creditLimit)) > 0) {
                throw CreditLedgerRefused::overLimit("The resulting balance [{$new}] exceeds the credit limit [{$position->creditLimit}].");
            }
        }

        return new CreditOutcome(Decimal::canonical($new));
    }

    /**
     * The running balances a fixed opening balance plus a sequence of
     * entries would produce, by the identical addition `apply()` uses - the
     * test oracle `CreditLedgerInvariantTest` compares the stored chain
     * against (Ruling 4). Never called by a handler (step 16's source scan
     * asserts that).
     *
     * @param  list<array{debit: int|string, credit: int|string}>  $entries
     * @return list<string>
     */
    public static function fold(array $entries, string $opening = '0'): array
    {
        $balance = BigDecimal::of($opening);
        $running = [];

        foreach ($entries as $entry) {
            $debitAmount = Decimal::parseUnsigned($entry['debit']);
            $creditAmount = Decimal::parseUnsigned($entry['credit']);

            if ($debitAmount === null || $creditAmount === null) {
                throw new InvalidArgumentException('fold() received a malformed debit or credit amount.');
            }

            $balance = $balance->plus($debitAmount)->minus($creditAmount);
            $running[] = Decimal::canonical($balance);
        }

        return $running;
    }
}
