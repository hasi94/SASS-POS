<?php

declare(strict_types=1);

namespace Flexipos\Customers\Loyalty;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Customers\LoyaltyLedgerRefused;
use Flexipos\Contracts\Customers\LoyaltyPosting;
use Flexipos\Contracts\Customers\LoyaltyTransactionType;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Decimal;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * The one internal writer of `loyalty_transactions` and
 * `customers.loyalty_points`/`loyalty_tier` (T-028 plan Ruling 6, the
 * `CreditLedgerWriter` shape applied to loyalty). `ContractLoyaltyLedger`,
 * `RecordLoyaltyTransactionHandler` and `MergeCustomers` all go through
 * this - nothing else writes either table or either maintained column, and
 * `QaCustomersSourceRulesTest` checks that.
 *
 * Inside `$connection->transaction()` (a savepoint when the caller already
 * has one open):
 *
 * 1. a caller-supplied `id` is a replay key (F-15): the same id and payload
 *    is a no-op that returns the original, `replayed = true`; a different
 *    payload is `replay_conflict`. No `id` means no replay check at all -
 *    the row is always inserted;
 * 2. `SELECT ... FOR UPDATE` on `customers`, then live/unmerged (D-30);
 * 3. the program (existence and `is_active` for `earn`/`redeem`, D-23/F-6)
 *    and employee checks;
 * 4. `LoyaltyCalculator::apply()` on the locked row's own values;
 * 5. `INSERT` the loyalty row with `balance_after`;
 * 6. `UPDATE customers.loyalty_points`, and `loyalty_tier` recomputed from
 *    the written transaction's own program (AC-32, F-18). `version` is
 *    never incremented (Ruling 6).
 * 7. one `audit_logs` row.
 *
 * **Concurrent replays** fall back on the primary key itself: if the
 * pre-check misses (or there was no `id` to check) and the `INSERT` then
 * violates a unique constraint, the row is re-read and compared under the
 * same rule.
 */
final class LoyaltyWriter
{
    public const ACTION = 'customers_loyalty_transaction_recorded';

    public function __construct(
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws LoyaltyLedgerRefused
     */
    public function post(
        Connection $connection,
        ?string $id,
        string $customerId,
        string $programId,
        LoyaltyTransactionType $type,
        int|string $points,
        int|string|null $value,
        ?string $expiresAt,
        ?string $employeeId,
        ?string $notes,
        ?string $actorId,
    ): LoyaltyPosting {
        return $connection->transaction(function () use (
            $connection, $id, $customerId, $programId, $type, $points, $value, $expiresAt, $employeeId, $notes, $actorId,
        ): LoyaltyPosting {
            if ($id !== null) {
                $existing = $connection->table('loyalty_transactions')->where('id', $id)->first();

                if ($existing !== null) {
                    return $this->resolveReplay($connection, $existing, $customerId, $programId, $type, $points, $value);
                }
            }

            $customer = $connection->table('customers')->where('id', $customerId)->lockForUpdate()->first();

            if ($customer === null) {
                throw LoyaltyLedgerRefused::unknownCustomer("No customer has id [{$customerId}].");
            }

            if ($customer->deleted_at !== null || $customer->merged_into_id !== null) {
                throw LoyaltyLedgerRefused::customerNotLive("Customer [{$customerId}] is not live.");
            }

            $program = $connection->table('loyalty_programs')->where('id', $programId)->first();

            if ($program === null) {
                throw LoyaltyLedgerRefused::unknownProgram("No loyalty program has id [{$programId}].");
            }

            $gatedByActive = $type === LoyaltyTransactionType::Earn || $type === LoyaltyTransactionType::Redeem;

            if ($gatedByActive && ! (bool) $program->is_active) {
                throw LoyaltyLedgerRefused::programInactive("Loyalty program [{$programId}] is not active.");
            }

            if ($employeeId !== null && $connection->table('employees')->where('id', $employeeId)->first() === null) {
                throw LoyaltyLedgerRefused::unknownEmployee("No employee has id [{$employeeId}].");
            }

            $valueAmount = self::checkValue($value);

            $outcome = LoyaltyCalculator::apply((string) $customer->loyalty_points, $type, $points);

            $transactionId = $id ?? (string) Str::ulid();
            $now = $this->clock->timestamp();

            $pointsAmount = Decimal::canonical(Decimal::parseSigned($points) ?? BigDecimal::zero());

            $tierRules = TierRules::parse($program->tier_rules === null ? null : json_decode((string) $program->tier_rules, true));
            $newTier = TierRules::tierFor($tierRules, $outcome->balanceAfter);

            try {
                $connection->table('loyalty_transactions')->insert([
                    'id' => $transactionId,
                    'origin' => 'online',
                    'terminal_id' => null,
                    'device_id' => null,
                    'client_seq' => null,
                    'client_created_at' => null,
                    'sync_status' => 'synced',
                    'synced_at' => null,
                    'customer_id' => $customerId,
                    'program_id' => $programId,
                    'type' => $type->value,
                    'points' => $pointsAmount,
                    'value' => $valueAmount,
                    'balance_after' => $outcome->balanceAfter,
                    'expires_at' => $expiresAt,
                    'employee_id' => $employeeId,
                    'notes' => $notes,
                    'created_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = $connection->table('loyalty_transactions')->where('id', $transactionId)->first();

                if ($existing === null) {
                    throw LoyaltyLedgerRefused::replayConflict('The transaction could not be read back after a unique-key conflict.');
                }

                return $this->resolveReplay($connection, $existing, $customerId, $programId, $type, $points, $value);
            }

            $connection->table('customers')->where('id', $customerId)->update([
                'loyalty_points' => $outcome->balanceAfter,
                'loyalty_tier' => $newTier,
                'updated_at' => $now,
            ]);

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $actorId,
                self::ACTION,
                'loyalty_transaction',
                $transactionId,
                null,
                [
                    'customer_id' => $customerId,
                    'program_id' => $programId,
                    'type' => $type->value,
                    'points' => $pointsAmount,
                    'value' => $valueAmount,
                    'balance_after' => $outcome->balanceAfter,
                    'tier' => $newTier,
                ],
            );

            return new LoyaltyPosting(
                $transactionId,
                $customerId,
                $programId,
                $type,
                $pointsAmount,
                $outcome->balanceAfter,
                $newTier,
                false,
            );
        });
    }

    /**
     * @throws LoyaltyLedgerRefused invalidValue
     */
    private static function checkValue(int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $parsed = Decimal::parseSigned($value);

        if ($parsed === null) {
            throw LoyaltyLedgerRefused::invalidValue("The value [{$value}] is not an int or a signed DECIMAL(18,4) string.");
        }

        return Decimal::canonical($parsed);
    }

    /**
     * F-15: a replay matching the stored row's own payload is a no-op that
     * returns the original; any mismatch is `replay_conflict`.
     *
     * @throws LoyaltyLedgerRefused replayConflict
     */
    private function resolveReplay(
        Connection $connection,
        object $existing,
        string $customerId,
        string $programId,
        LoyaltyTransactionType $type,
        int|string $points,
        int|string|null $value,
    ): LoyaltyPosting {
        $pointsAmount = Decimal::canonical(Decimal::parseSigned($points) ?? BigDecimal::zero());
        $valueAmount = $value === null ? null : Decimal::canonical(Decimal::parseSigned($value) ?? BigDecimal::zero());

        $existingPoints = Decimal::canonical(BigDecimal::of((string) $existing->points));
        $existingValue = $existing->value === null ? null : Decimal::canonical(BigDecimal::of((string) $existing->value));
        $existingBalanceAfter = Decimal::canonical(BigDecimal::of((string) $existing->balance_after));

        $matches = (string) $existing->customer_id === $customerId
            && (string) $existing->program_id === $programId
            && (string) $existing->type === $type->value
            && $existingPoints === $pointsAmount
            && $existingValue === $valueAmount;

        if (! $matches) {
            throw LoyaltyLedgerRefused::replayConflict("A different transaction already exists for this id [{$existing->id}].");
        }

        $customer = $connection->table('customers')->where('id', (string) $existing->customer_id)->first();

        return new LoyaltyPosting(
            (string) $existing->id,
            (string) $existing->customer_id,
            (string) $existing->program_id,
            LoyaltyTransactionType::from((string) $existing->type),
            $existingPoints,
            $existingBalanceAfter,
            $customer === null ? null : $customer->loyalty_tier,
            true,
        );
    }
}
