<?php

declare(strict_types=1);

namespace Flexipos\Customers\Ledger;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Contracts\Customers\CreditLedgerPosting;
use Flexipos\Contracts\Customers\CreditLedgerRefused;
use Flexipos\Customers\Features\RecordCreditLedgerEntry\RecordCreditLedgerEntryHandler;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Decimal;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * The one internal writer of `customer_ledger` and `customers.credit_balance`
 * (T-028 plan Ruling 6). `ContractCreditLedger`, `CreateCustomer` (the
 * opening entry, F-11) and the future `MergeCustomers` all go through this
 * - nothing else writes either table or either maintained column, and
 * `QaCustomersSourceRulesTest` checks that.
 *
 * Inside `$connection->transaction()` (a savepoint when the caller already
 * has one open):
 *
 * 1. replay pre-check by `(reference_type, reference_id, type)` (D-21, F-4);
 * 2. `SELECT ... FOR UPDATE` on `customers`, then live/unmerged, status and
 *    currency checks (F-8, F-13, D-25, D-30);
 * 3. the branch and employee checks;
 * 4. `CreditLedgerCalculator::apply()` on the locked row's own values;
 * 5. `INSERT` the ledger row with `balance_after`;
 * 6. `UPDATE customers.credit_balance`. `version` is never incremented
 *    (Ruling 6).
 * 7. one `audit_logs` row.
 *
 * **Concurrent replays** fall back on the ledger's own unique index: if the
 * pre-check misses and the `INSERT` then violates it, the row is re-read and
 * compared under the same rule.
 */
final class CreditLedgerWriter
{
    public const ACTION = 'customers_credit_ledger_entry_recorded';

    /**
     * `reference_type` values reserved for internal callers only (R-1):
     * `CreateCustomer`'s opening entry and `MergeCustomers`' compensating
     * pair. `reference_type` is otherwise free text on the public entry
     * points ({@see RecordCreditLedgerEntryHandler},
     * {@see ContractCreditLedger}), so without
     * this reservation a caller could pre-seed one of these replay keys and
     * make the merge's own compensating post silently replay as a no-op
     * while the source still holds a balance.
     *
     * @var list<string>
     */
    public const RESERVED_REFERENCE_TYPES = ['customer', 'customer_merge_out', 'customer_merge_in'];

    public function __construct(
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws CreditLedgerRefused
     */
    public function post(
        Connection $connection,
        string $customerId,
        CreditEntryType $type,
        string $referenceType,
        string $referenceId,
        int|string $debit,
        int|string $credit,
        ?string $currency,
        ?string $branchId,
        ?string $employeeId,
        ?string $notes,
        ?string $actorId,
    ): CreditLedgerPosting {
        return $connection->transaction(function () use (
            $connection, $customerId, $type, $referenceType, $referenceId, $debit, $credit, $currency, $branchId, $employeeId, $notes, $actorId,
        ): CreditLedgerPosting {
            $existing = $connection->table('customer_ledger')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('type', $type->value)
                ->first();

            if ($existing !== null) {
                return $this->resolveReplay($existing, $customerId, $debit, $credit, $currency);
            }

            $customer = $connection->table('customers')->where('id', $customerId)->lockForUpdate()->first();

            if ($customer === null) {
                throw CreditLedgerRefused::unknownCustomer("No customer has id [{$customerId}].");
            }

            if ($customer->deleted_at !== null || $customer->merged_into_id !== null) {
                throw CreditLedgerRefused::customerNotLive("Customer [{$customerId}] is not live.");
            }

            $resolvedCurrency = $this->resolveCurrency($customer, $currency);

            if ($branchId !== null && $connection->table('branches')->where('id', $branchId)->first() === null) {
                throw CreditLedgerRefused::unknownBranch("No branch has id [{$branchId}].");
            }

            if ($employeeId !== null && $connection->table('employees')->where('id', $employeeId)->first() === null) {
                throw CreditLedgerRefused::unknownEmployee("No employee has id [{$employeeId}].");
            }

            $position = new CreditPosition(
                (string) $customer->credit_balance,
                (bool) $customer->credit_allowed,
                (string) $customer->credit_limit,
                $customer->status === 'active',
            );

            $outcome = CreditLedgerCalculator::apply($position, $type, $debit, $credit);

            $entryId = (string) Str::ulid();
            $now = $this->clock->timestamp();

            $debitAmount = Decimal::canonical(Decimal::parseUnsigned($debit) ?? BigDecimal::zero());
            $creditAmount = Decimal::canonical(Decimal::parseUnsigned($credit) ?? BigDecimal::zero());

            try {
                $connection->table('customer_ledger')->insert([
                    'id' => $entryId,
                    'customer_id' => $customerId,
                    'branch_id' => $branchId,
                    'type' => $type->value,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'debit' => $debitAmount,
                    'credit' => $creditAmount,
                    'balance_after' => $outcome->balanceAfter,
                    'currency' => $resolvedCurrency,
                    'employee_id' => $employeeId,
                    'notes' => $notes,
                    'created_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A locking read, not the plain `first()` above: under
                // InnoDB REPEATABLE READ a plain read here would still see
                // the pre-transaction snapshot and could miss the row the
                // other connection just committed (W-1), reporting a false
                // `replay_conflict` for a legitimate concurrent retry.
                $existing = $connection->table('customer_ledger')
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->where('type', $type->value)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    throw CreditLedgerRefused::replayConflict('The entry could not be read back after a unique-key conflict.');
                }

                return $this->resolveReplay($existing, $customerId, $debit, $credit, $currency);
            }

            $connection->table('customers')->where('id', $customerId)->update([
                'credit_balance' => $outcome->balanceAfter,
                'updated_at' => $now,
            ]);

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $actorId,
                self::ACTION,
                'customer_ledger',
                $entryId,
                null,
                [
                    'customer_id' => $customerId,
                    'type' => $type->value,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'debit' => $debitAmount,
                    'credit' => $creditAmount,
                    'balance_after' => $outcome->balanceAfter,
                    'currency' => $resolvedCurrency,
                ],
            );

            return new CreditLedgerPosting(
                $entryId,
                $customerId,
                $type,
                $debitAmount,
                $creditAmount,
                $outcome->balanceAfter,
                $resolvedCurrency,
                false,
            );
        });
    }

    /**
     * @throws CreditLedgerRefused currencyRequired, currencyMismatch
     */
    private function resolveCurrency(object $customer, ?string $currency): string
    {
        if ($customer->currency === null) {
            throw CreditLedgerRefused::currencyRequired("Customer [{$customer->id}] has no currency set.");
        }

        if ($currency !== null && $currency !== $customer->currency) {
            throw CreditLedgerRefused::currencyMismatch("The entry's currency [{$currency}] does not match the customer's [{$customer->currency}].");
        }

        return (string) $customer->currency;
    }

    /**
     * D-21/F-4: a replay matching the stored row's own payload is a no-op
     * that returns the original; any mismatch is `replay_conflict`.
     *
     * @throws CreditLedgerRefused replayConflict
     */
    private function resolveReplay(object $existing, string $customerId, int|string $debit, int|string $credit, ?string $currency): CreditLedgerPosting
    {
        $debitAmount = Decimal::canonical(Decimal::parseUnsigned($debit) ?? BigDecimal::zero());
        $creditAmount = Decimal::canonical(Decimal::parseUnsigned($credit) ?? BigDecimal::zero());

        $existingDebit = Decimal::canonical(BigDecimal::of((string) $existing->debit));
        $existingCredit = Decimal::canonical(BigDecimal::of((string) $existing->credit));
        $existingBalanceAfter = Decimal::canonical(BigDecimal::of((string) $existing->balance_after));

        $matches = (string) $existing->customer_id === $customerId
            && $existingDebit === $debitAmount
            && $existingCredit === $creditAmount
            && ($currency === null || (string) $existing->currency === $currency);

        if (! $matches) {
            throw CreditLedgerRefused::replayConflict("A different entry already exists for this key, id [{$existing->id}].");
        }

        return new CreditLedgerPosting(
            (string) $existing->id,
            (string) $existing->customer_id,
            CreditEntryType::from((string) $existing->type),
            $existingDebit,
            $existingCredit,
            $existingBalanceAfter,
            (string) $existing->currency,
            true,
        );
    }
}
