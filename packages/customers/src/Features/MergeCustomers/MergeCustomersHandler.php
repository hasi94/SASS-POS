<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\MergeCustomers;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Contracts\Customers\LoyaltyTransactionType;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Ledger\CreditLedgerWriter;
use Flexipos\Customers\Loyalty\LoyaltyWriter;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;

/**
 * Merge a source customer into a target customer (T-028 plan Ruling 9,
 * AC-13, AC-14; D-20, D-25, D-29, D-30 - the final, overturned design: the
 * merge moves the **balance**, never the rows).
 *
 * One transaction:
 *
 * 1. fold both ids - `source = target` is `self_merge`;
 * 2. lock both rows in ascending id order, to avoid a deadlock against a
 *    concurrent merge of the same pair the other way round;
 * 3. each row must exist, be live and unmerged;
 * 4. a non-zero source balance requires matching currencies, then a
 *    compensating `adjustment` pair through {@see CreditLedgerWriter} -
 *    `customer_merge_out`/`customer_merge_in`, both carrying the source's
 *    own id as `reference_id`;
 * 5. a non-zero source loyalty balance posts a compensating `transfer` pair
 *    through {@see LoyaltyWriter}, under the program of the source's most
 *    recent loyalty transaction;
 * 6. addresses, notes and communications move by a direct `customer_id`
 *    update - the only rows with no append-only constraint. The source's
 *    `is_default` is cleared first for any `type` the target already
 *    defaults;
 * 7. the source is marked `merged_into_id` and soft-deleted - its balances
 *    are already zero by construction;
 * 8. one `customers_merged` audit row, in addition to the writers' own.
 */
final class MergeCustomersHandler
{
    public const ACTION = 'customers_merged';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly CreditLedgerWriter $creditWriter,
        private readonly LoyaltyWriter $loyaltyWriter,
    ) {}

    public function handle(MergeCustomers $command): void
    {
        $sourceId = Identifier::fold($command->sourceId, 'sourceId');
        $targetId = Identifier::fold($command->targetId, 'targetId');

        if ($sourceId === $targetId) {
            throw CustomersRefused::selfMerge("Customer [{$sourceId}] cannot be merged into itself.");
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $sourceId, $targetId): void {
            // Lock both rows in ascending id order (plan Ruling 6, "lock
            // order"), so two concurrent merges touching the same pair can
            // never deadlock against each other.
            $orderedIds = [$sourceId, $targetId];
            sort($orderedIds);

            $locked = [];

            foreach ($orderedIds as $id) {
                $locked[$id] = $connection->table('customers')->where('id', $id)->lockForUpdate()->first();
            }

            $source = $locked[$sourceId];
            $target = $locked[$targetId];

            if ($source === null) {
                throw CustomersRefused::notFound("No customer has id [{$sourceId}] (source).");
            }

            if ($target === null) {
                throw CustomersRefused::notFound("No customer has id [{$targetId}] (target).");
            }

            self::checkLive($source, 'source');
            self::checkLive($target, 'target');

            $sourceBalance = BigDecimal::of((string) $source->credit_balance);

            if (! $sourceBalance->isZero()) {
                if ((string) $source->currency !== (string) $target->currency) {
                    throw CustomersRefused::currencyMismatch("Customer [{$sourceId}]'s currency [{$source->currency}] does not match [{$targetId}]'s [{$target->currency}].");
                }

                $this->transferCreditBalance($connection, $sourceId, $targetId, $sourceBalance, (string) $source->currency, $command->actorId);
            }

            $sourcePoints = BigDecimal::of((string) $source->loyalty_points);

            if (! $sourcePoints->isZero()) {
                $this->transferLoyaltyBalance($connection, $sourceId, $targetId, $sourcePoints, $command->actorId);
            }

            self::moveAddresses($connection, $sourceId, $targetId, $this->clock->timestamp());
            self::moveChildren($connection, 'customer_notes', $sourceId, $targetId, $this->clock->timestamp());
            self::moveChildren($connection, 'customer_communications', $sourceId, $targetId, $this->clock->timestamp());

            // R-1: "zero by construction" is only true if the compensating
            // posts above actually wrote a row. `reference_type` is free
            // text on the public ledger entry points, so a pre-seeded
            // (`customer_merge_out`/`customer_merge_in`, sourceId,
            // adjustment) row would make `post()` replay as a no-op instead
            // of zeroing the balance. Re-read the source under lock and
            // refuse rather than soft-delete a customer still holding a
            // balance or loyalty points.
            $sourceAfter = $connection->table('customers')->where('id', $sourceId)->lockForUpdate()->first();

            if ($sourceAfter === null || ! BigDecimal::of((string) $sourceAfter->credit_balance)->isZero() || ! BigDecimal::of((string) $sourceAfter->loyalty_points)->isZero()) {
                throw CustomersRefused::validation("Customer [{$sourceId}]'s balance was not reduced to zero by the merge; refusing to merge.");
            }

            $now = $this->clock->timestamp();

            $connection->table('customers')->where('id', $sourceId)->update([
                'merged_into_id' => $targetId,
                'deleted_at' => $now,
                'updated_at' => $now,
                'version' => (int) $source->version + 1,
            ]);

            $after = $connection->table('customers')->where('id', $sourceId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer',
                $sourceId,
                (array) $source,
                $after === null ? null : (array) $after,
            );
        });
    }

    /**
     * @throws CustomersRefused notLive, alreadyMerged
     */
    private static function checkLive(object $customer, string $role): void
    {
        if ($customer->deleted_at !== null) {
            throw CustomersRefused::notLive("The {$role} customer [{$customer->id}] is not live.");
        }

        if ($customer->merged_into_id !== null) {
            throw CustomersRefused::alreadyMerged("The {$role} customer [{$customer->id}] is already merged into [{$customer->merged_into_id}].");
        }
    }

    /**
     * A compensating `adjustment` pair zeroing the source's balance and
     * replicating it onto the target (D-20, AC-13): the source takes the
     * side opposite its own balance's sign (to cancel it), and the target
     * takes the side matching it (to replicate it) - a positive balance
     * (debit-heavy) zeroes with a credit and mirrors with a debit; a
     * negative balance (credit-heavy) zeroes with a debit and mirrors with
     * a credit.
     */
    private function transferCreditBalance(
        Connection $connection,
        string $sourceId,
        string $targetId,
        BigDecimal $sourceBalance,
        string $currency,
        ?string $actorId,
    ): void {
        $magnitude = (string) $sourceBalance->abs();
        $isDebitHeavy = $sourceBalance->isPositive();

        $out = $this->creditWriter->post(
            $connection,
            $sourceId,
            CreditEntryType::Adjustment,
            'customer_merge_out',
            $sourceId,
            $isDebitHeavy ? 0 : $magnitude,
            $isDebitHeavy ? $magnitude : 0,
            $currency,
            null,
            null,
            null,
            $actorId,
        );

        $in = $this->creditWriter->post(
            $connection,
            $targetId,
            CreditEntryType::Adjustment,
            'customer_merge_in',
            $sourceId,
            $isDebitHeavy ? $magnitude : 0,
            $isDebitHeavy ? 0 : $magnitude,
            $currency,
            null,
            null,
            null,
            $actorId,
        );

        // R-1: a pre-seeded collision on either replay key would make
        // `post()` return the stored row unchanged instead of zeroing the
        // source/crediting the target - refuse rather than merge on a
        // silent no-op.
        if ($out->replayed || $in->replayed) {
            throw CustomersRefused::validation("A credit ledger entry for customer [{$sourceId}]'s merge already exists; refusing to merge.");
        }
    }

    /**
     * A compensating `transfer` pair zeroing the source's loyalty balance
     * and replicating it onto the target, under the program of the
     * source's most recent loyalty transaction (D-20, AC-13, F-18).
     */
    private function transferLoyaltyBalance(
        Connection $connection,
        string $sourceId,
        string $targetId,
        BigDecimal $sourcePoints,
        ?string $actorId,
    ): void {
        $latest = $connection->table('loyalty_transactions')
            ->where('customer_id', $sourceId)
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            throw CustomersRefused::validation("Customer [{$sourceId}] has a non-zero loyalty balance with no loyalty transaction to read a program from.");
        }

        $programId = (string) $latest->program_id;
        $zeroing = (string) $sourcePoints->negated();
        $mirrored = (string) $sourcePoints;

        $this->loyaltyWriter->post(
            $connection,
            null,
            $sourceId,
            $programId,
            LoyaltyTransactionType::Transfer,
            $zeroing,
            null,
            null,
            null,
            null,
            $actorId,
        );

        $this->loyaltyWriter->post(
            $connection,
            null,
            $targetId,
            $programId,
            LoyaltyTransactionType::Transfer,
            $mirrored,
            null,
            null,
            null,
            null,
            $actorId,
        );
    }

    /**
     * Move the source's addresses onto the target (AC-13): the source's own
     * default is cleared first for any `type` the target already defaults,
     * so the move never collides with `customer_addresses`' unique backstop.
     */
    private static function moveAddresses(Connection $connection, string $sourceId, string $targetId, string $now): void
    {
        $targetDefaultTypes = $connection->table('customer_addresses')
            ->where('customer_id', $targetId)
            ->where('is_default', true)
            ->pluck('type')
            ->all();

        if ($targetDefaultTypes !== []) {
            $connection->table('customer_addresses')
                ->where('customer_id', $sourceId)
                ->where('is_default', true)
                ->whereIn('type', $targetDefaultTypes)
                ->update(['is_default' => false]);
        }

        $connection->table('customer_addresses')
            ->where('customer_id', $sourceId)
            ->update(['customer_id' => $targetId, 'updated_at' => $now]);
    }

    /**
     * Move every row of `$table` from the source to the target by a plain
     * `customer_id` reassignment (AC-13: notes and communications carry no
     * append-only constraint).
     */
    private static function moveChildren(Connection $connection, string $table, string $sourceId, string $targetId, string $now): void
    {
        $connection->table($table)
            ->where('customer_id', $sourceId)
            ->update(['customer_id' => $targetId, 'updated_at' => $now]);
    }
}
