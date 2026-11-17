<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tenders;

use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Support\Money;

/**
 * What a payment method allows a tender to be (AC-11, AC-12, D-41, F-17).
 *
 * Pure: it takes the method's row, what was offered and the sale's balance, and
 * either answers with a {@see TenderOutcome} or refuses. It reads no table and
 * writes nothing, so the slice that calls it decides when the method row is
 * read and under which lock.
 *
 * The refusals, in the order they are tried:
 *
 * 1. `validation`: the offer is not a positive amount;
 * 2. `unsupported_method_type`: `loyalty` and `voucher` (D-41), or, on a
 *    refund only, a method whose `allows_refund` is false (AC-12A, D-71). The
 *    refund flag is never a code of its own and an ordinary sale tender never
 *    reads it;
 * 3. `method_inactive`;
 * 4. `reference_required`: the method requires one and none (or a blank) came;
 * 5. nothing owing: a sale with no balance takes no tender (`exceeds_balance`);
 * 6. over the balance: `exceeds_balance`, **unless** the method gives change,
 *    in which case the amount is the balance and the rest is change. A refund
 *    gives no change (a payout is not a sale): over what is owed back is
 *    `exceeds_balance` whatever `allows_change` says;
 * 7. `partial_not_allowed`: the method does not allow part payment and the
 *    amount is short of the balance.
 *
 * With `$refund = true`, `$offered` and `$balance` are **magnitudes**: what the
 * drawer pays out and what is still owed back. Both are positive and the caller
 * negates the stored amount.
 *
 * `$offered` is the money put on the counter: for a method that gives change,
 * the tendered figure; for any other, the amount. A method that gives change
 * records `tendered` even when it is exact; any other (and every refund)
 * records null.
 */
final class TenderRules
{
    /**
     * @param  object{type: string, is_active: int|bool, requires_reference: int|bool, allows_change: int|bool, allows_partial: int|bool, allows_refund?: int|bool}  $method  `allows_refund` is read only when `$refund` is true
     *
     * @throws SalesRefused validation, unsupported_method_type, method_inactive,
     *                      reference_required, exceeds_balance, partial_not_allowed
     */
    public static function decide(object $method, string $offered, ?string $reference, string $balance, bool $refund = false): TenderOutcome
    {
        if (! Money::isPositive($offered)) {
            throw SalesRefused::validation('A tender must be greater than zero.');
        }

        $type = MethodType::tryFrom((string) $method->type);

        if ($type === null || ! $type->isTenderable()) {
            throw SalesRefused::because(SalesRefusal::UnsupportedMethodType, "A [{$method->type}] method cannot take a tender.");
        }

        if ($refund && ! (bool) ($method->allows_refund ?? false)) {
            throw SalesRefused::because(SalesRefusal::UnsupportedMethodType, 'The payment method is not refundable.');
        }

        if (! (bool) $method->is_active) {
            throw SalesRefused::because(SalesRefusal::MethodInactive, 'The payment method is inactive.');
        }

        if ((bool) $method->requires_reference && ($reference === null || trim($reference) === '')) {
            throw SalesRefused::because(SalesRefusal::ReferenceRequired, 'The payment method requires a reference.');
        }

        if (! Money::isPositive($balance)) {
            throw SalesRefused::because(SalesRefusal::ExceedsBalance, 'The sale has no balance to pay.');
        }

        $givesChange = ! $refund && (bool) $method->allows_change;

        if (Money::compare($offered, $balance) > 0) {
            if (! $givesChange) {
                throw SalesRefused::because(SalesRefusal::ExceedsBalance, "The tender of {$offered} exceeds the balance of {$balance}.");
            }

            return new TenderOutcome(Money::of($balance), Money::of($offered), Money::subtract($offered, $balance));
        }

        if (! (bool) $method->allows_partial && Money::compare($offered, $balance) < 0) {
            throw SalesRefused::because(SalesRefusal::PartialNotAllowed, "The payment method does not allow part payment; {$balance} is owing.");
        }

        return new TenderOutcome(Money::of($offered), $givesChange ? Money::of($offered) : null, Money::ZERO);
    }
}
