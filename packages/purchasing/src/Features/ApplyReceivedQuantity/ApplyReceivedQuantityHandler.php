<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ApplyReceivedQuantity;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\OrderEvents;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderLines;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\ReceiptRollup;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Support\Decimal;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\Money;
use Flexipos\Purchasing\Support\TenantConnection;
use Illuminate\Database\Connection;

/**
 * Apply one receipt delta to an order line and roll the order's status up from the
 * stored base sums (T-034 AC-69 to AC-80, AC-96; Rulings 4, 5, 7, 11; D-44, D-47).
 *
 * **An internal operation for FP-255's own transaction.** It carries **no actor
 * permission check**: the calling slice (the receive) owns the permission and the
 * branch check, and calls this handler directly in the same package, inside its own
 * transaction. This handler opens a nested `transaction()` (a savepoint inside that
 * one, a transaction of its own when called alone), never retries, and takes the
 * **order header lock first**, before any line (Ruling 7); the line is found by a
 * plain read only to learn which order to lock, then read again under the lock.
 * `actorId` is recorded on the application and the audit row and may be null; a
 * non-null id that names no employee is `validation` ("Unknown actor.").
 *
 * **The deltas.** `received_base_delta` and `received_quantity_delta` are a
 * `DECIMAL(18,4)` string or an int, never a float. The base delta is never zero and
 * the two never have opposite signs; a quantity delta of zero is **allowed** beside a
 * non-zero base delta (a small base delta can round to zero in a large line unit,
 * D-47), and a non-zero quantity delta beside a zero base delta is refused. A
 * delta that would take either received column below zero is `out_of_range` (AC-74).
 * Anything malformed is `validation`.
 *
 * **Which orders accept a receipt** (AC-75, D-44): `approved`, `partially_received`
 * and `received` (a `received` order takes any non-zero delta, a reversal or an
 * over-receipt); `draft`, `pending_approval`, `sent`, `closed` and `cancelled` are
 * `order_not_open`; an unknown line is `not_found`. Over-receipt is accepted as
 * given (AC-73).
 *
 * **The status** is recomputed from the stored `base_quantity` and
 * `received_base_quantity` of every line and never from `received_quantity`
 * ({@see ReceiptRollup::status()}): all zero is `approved`, every line complete
 * `received`, otherwise `partially_received`. The rollup is written as it comes out,
 * so a single-line order reversed to zero from `received` returns to `approved`.
 *
 * **Replay by application id** (AC-76): the same id with the same line and the same
 * two deltas returns `replayed = true`, moves nothing and writes no audit row and no
 * event, whatever the order's status has become since; the same id with another
 * line or other deltas is `replay_conflict` naming it. A real application writes one
 * application row, one `purchasing_order_receipt_applied` audit row and one
 * `purchase_order.received_applied` event. This slice reads and writes no stock,
 * movement, GRN, journal or incoming-quantity figure (AC-79).
 */
final class ApplyReceivedQuantityHandler
{
    private const FIELDS = ['application_id', 'line_id', 'received_base_delta', 'received_quantity_delta'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly OrderEvents $events,
    ) {}

    public function handle(ApplyReceivedQuantity $command): ApplyReceivedQuantityResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $applicationId = Identifier::fold($fields['application_id'] ?? null, 'application_id');
        $lineId = Identifier::fold($fields['line_id'] ?? null, 'line_id');
        $actorId = Identifier::foldNullable($command->actorId, 'actor');

        [$baseDelta, $quantityDelta] = $this->deltas($fields);

        $connection = $this->tenant->get();

        // The application row's `created_by` is a foreign key to employees, so an id
        // that names no employee is refused here as validation, not left to surface
        // as a QueryException. A null actor is allowed and skips the check.
        if ($actorId !== null && ! $connection->table('employees')->where('id', $actorId)->exists()) {
            throw PurchasingRefused::validation('Unknown actor.');
        }

        // A plain read, to learn which order to lock. Nothing is decided from it.
        $orderId = strtoupper((string) Lookup::any($connection, 'purchase_order_items', $lineId, 'purchase order line')->order_id);

        return $connection->transaction(function () use ($connection, $orderId, $lineId, $applicationId, $actorId, $baseDelta, $quantityDelta): ApplyReceivedQuantityResult {
            $locked = OrderImage::header(DocumentLocks::order($connection, $orderId));

            $existing = $connection->table('purchase_order_receipt_applications')->where('id', $applicationId)->first();

            if ($existing !== null) {
                return $this->replay($connection, $existing, $orderId, $lineId, $applicationId, $baseDelta, $quantityDelta, $locked);
            }

            $status = OrderStatus::from($locked['status']);

            if (! $status->acceptsReceipt()) {
                throw PurchasingRefused::because(PurchasingRefusal::OrderNotOpen, "Purchase order [{$locked['number']}] is [{$status->value}] and takes no receipt.");
            }

            $before = OrderImage::line(OrderLines::line($connection, $orderId, $lineId, true));

            $receivedBase = ReceiptRollup::apply($before['received_base_quantity'], $baseDelta);
            $receivedQuantity = ReceiptRollup::apply($before['received_quantity'], $quantityDelta);

            $connection->table('purchase_order_items')->where('id', $lineId)->update([
                'received_base_quantity' => $receivedBase,
                'received_quantity' => $receivedQuantity,
            ]);

            $now = $this->clock->timestamp();

            $connection->table('purchase_order_receipt_applications')->insert([
                'id' => $applicationId,
                'order_id' => $orderId,
                'order_item_id' => $lineId,
                'received_base_delta' => $baseDelta,
                'received_quantity_delta' => $quantityDelta,
                'created_by' => $actorId,
                'created_at' => $now,
            ]);

            $rolled = ReceiptRollup::status(
                $connection->table('purchase_order_items')
                    ->where('order_id', $orderId)
                    ->get(['base_quantity', 'received_base_quantity'])
                    ->map(static fn (object $row): array => (array) $row)
                    ->all(),
            );

            if ($rolled !== $status) {
                $status->assertCanMoveTo($rolled);

                $connection->table('purchase_orders')->where('id', $orderId)->update([
                    'status' => $rolled->value,
                    'updated_at' => $now,
                    'updated_by' => $actorId,
                ]);
            }

            $after = OrderImage::line($connection->table('purchase_order_items')->where('id', $lineId)->first()
                ?? throw PurchasingRefused::notFound("No purchase order line has id [{$lineId}]."));

            $order = OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::ORDER_RECEIPT_APPLIED,
                'purchase_order',
                $orderId,
                $before,
                array_merge($after, [
                    'application_id' => $applicationId,
                    'received_base_delta' => $baseDelta,
                    'received_quantity_delta' => $quantityDelta,
                    'order_status' => $order['status'],
                ]),
                (string) $order['branch_id'],
            );

            $this->events->receivedApplied($order, $after, $applicationId, $baseDelta, $quantityDelta);

            return new ApplyReceivedQuantityResult($applicationId, $orderId, $lineId, $order['status'], false, $after, $order);
        });
    }

    /**
     * An application id that has been applied: the same line and deltas return the
     * order as it stands, anything else is `replay_conflict`.
     *
     * @param  array<string, mixed>  $order  the locked header image
     *
     * @throws PurchasingRefused replay_conflict
     */
    private function replay(Connection $connection, object $existing, string $orderId, string $lineId, string $applicationId, string $baseDelta, string $quantityDelta, array $order): ApplyReceivedQuantityResult
    {
        $same = strtoupper((string) $existing->order_item_id) === $lineId
            && Money::equals(Money::of($existing->received_base_delta), $baseDelta)
            && Money::equals(Money::of($existing->received_quantity_delta), $quantityDelta);

        if (! $same) {
            throw PurchasingRefused::because(PurchasingRefusal::ReplayConflict, "Receipt application [{$applicationId}] was already applied with a different line or different deltas.");
        }

        return new ApplyReceivedQuantityResult(
            $applicationId,
            $orderId,
            $lineId,
            $order['status'],
            true,
            OrderImage::line(OrderLines::line($connection, $orderId, $lineId)),
            $order,
        );
    }

    /**
     * The two deltas, validated (AC-77, D-47).
     *
     * @param  array<string, mixed>  $fields
     * @return array{0: string, 1: string} the base delta and the quantity delta, as four-decimal strings
     *
     * @throws PurchasingRefused validation
     */
    private function deltas(array $fields): array
    {
        $base = $this->delta($fields['received_base_delta'] ?? null, 'received_base_delta');
        $quantity = $this->delta($fields['received_quantity_delta'] ?? null, 'received_quantity_delta');

        if (Money::isZero($base)) {
            throw PurchasingRefused::validation('The received_base_delta must not be zero.');
        }

        if (! Money::isZero($quantity) && Money::isPositive($base) !== Money::isPositive($quantity)) {
            throw PurchasingRefused::validation('The received_base_delta and the received_quantity_delta must have the same sign.');
        }

        return [$base, $quantity];
    }

    /**
     * @throws PurchasingRefused validation
     */
    private function delta(mixed $submitted, string $name): string
    {
        if (! is_int($submitted) && ! is_string($submitted)) {
            throw PurchasingRefused::validation("The {$name} must be a DECIMAL(18,4) string or an int.");
        }

        $parsed = Decimal::parseSigned($submitted);

        if ($parsed === null || ! Decimal::fitsEighteenFour($parsed)) {
            throw PurchasingRefused::validation("The {$name} must be a DECIMAL(18,4) string or an int.");
        }

        return Decimal::canonical($parsed);
    }
}
