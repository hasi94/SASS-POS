<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Contracts\Events\NewEvent;
use Flexipos\Contracts\Events\Outbox;
use Flexipos\Purchasing\Support\Money;
use Illuminate\Database\Connection;

/**
 * The outbox events an order writes (T-034 Ruling 11, AC-97, D-49, D-51).
 *
 * Every event is recorded **inside the caller's transaction** (the outbox writer
 * never opens one), against the `purchase_order` aggregate and the order's branch.
 * Ids and base figures only: never a name, an address or a `bank_details` value.
 *
 * The four lifecycle events (`approved`, `reopened`, `cancelled`, `closed`) carry
 * one payload, `{order_id, location_id, lines: [{line_id, product_id,
 * base_quantity, received_base_quantity}]}`, read from the stored lines at the
 * moment of the write. `received_applied` has its own. A replay or a refusal never
 * reaches this class.
 */
final class OrderEvents
{
    public const APPROVED = 'purchase_order.approved';

    public const REOPENED = 'purchase_order.reopened';

    public const CANCELLED = 'purchase_order.cancelled';

    public const CLOSED = 'purchase_order.closed';

    public const RECEIVED_APPLIED = 'purchase_order.received_applied';

    public function __construct(private readonly Outbox $outbox) {}

    /**
     * One of the four lifecycle events, with the order's stored lines.
     *
     * @param  string  $type  one of {@see self::APPROVED}, {@see self::REOPENED}, {@see self::CANCELLED}, {@see self::CLOSED}
     * @param  array<string, mixed>  $header  the stored header image (after the write)
     */
    public function lifecycle(Connection $connection, string $type, array $header): void
    {
        $lines = $connection->table('purchase_order_items')
            ->where('order_id', $header['id'])
            ->orderBy('line_no')
            ->get(['id', 'product_id', 'base_quantity', 'received_base_quantity'])
            ->map(static fn (object $line): array => [
                'line_id' => strtoupper((string) $line->id),
                'product_id' => strtoupper((string) $line->product_id),
                'base_quantity' => Money::of($line->base_quantity),
                'received_base_quantity' => Money::of($line->received_base_quantity),
            ])
            ->all();

        $this->outbox->record(new NewEvent(
            $type,
            'purchase_order',
            (string) $header['id'],
            ['order_id' => $header['id'], 'location_id' => $header['location_id'], 'lines' => $lines],
            branchId: (string) $header['branch_id'],
        ));
    }

    /**
     * `purchase_order.received_applied`.
     *
     * @param  array<string, mixed>  $header  the stored header image (after the write)
     * @param  array<string, mixed>  $line  the stored line image (after the write)
     */
    public function receivedApplied(array $header, array $line, string $applicationId, string $baseDelta, string $quantityDelta): void
    {
        $this->outbox->record(new NewEvent(
            self::RECEIVED_APPLIED,
            'purchase_order',
            (string) $header['id'],
            [
                'order_id' => $header['id'],
                'application_id' => $applicationId,
                'line_id' => $line['id'],
                'product_id' => $line['product_id'],
                'location_id' => $header['location_id'],
                'received_base_delta' => $baseDelta,
                'received_quantity_delta' => $quantityDelta,
                'received_base_quantity' => $line['received_base_quantity'],
                'received_quantity' => $line['received_quantity'],
                'status' => $header['status'],
            ],
            branchId: (string) $header['branch_id'],
        ));
    }
}
