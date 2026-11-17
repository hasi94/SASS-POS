<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\RemovePurchaseOrderLine;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\Amendments;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderLines;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\Money;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Remove a line from a draft order (T-034 AC-49, D-48).
 *
 * The line is deleted, not flagged: a draft is the one place a row is removed, and
 * its before image is in the audit row. The header is locked `FOR UPDATE` first and
 * the line is read under it, so a line another session just removed is `not_found`
 * and not a silent success. Only a draft (`locked` or `order_closed` otherwise).
 *
 * **A line made by a request conversion** has a request line pointing at it; the
 * pointer is cleared in the same transaction, before the delete (the foreign key
 * would refuse it otherwise), and the audit row's after image records which request
 * lines were cleared. The request itself stays `converted` (AC-33). Removing the
 * last line leaves a valid draft whose totals are zero plus the shipping (AC-49).
 */
final class RemovePurchaseOrderLineHandler
{
    private const FIELDS = ['order_id', 'line_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(RemovePurchaseOrderLine $command): RemovePurchaseOrderLineResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $orderId = Identifier::fold($fields['order_id'] ?? null, 'order_id');
        $lineId = Identifier::fold($fields['line_id'] ?? null, 'line_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_MANAGE, $branchId);

        return $connection->transaction(function () use ($connection, $orderId, $lineId, $branchId, $actorId): RemovePurchaseOrderLineResult {
            $locked = DocumentLocks::order($connection, $orderId);

            Amendments::assertLineSetChange(OrderStatus::from((string) $locked->status));

            $before = OrderImage::line(OrderLines::line($connection, $orderId, $lineId, true));

            $pointers = $connection->table('purchase_request_items')
                ->where('purchase_order_item_id', $lineId)
                ->pluck('id')
                ->map(static fn (mixed $id): string => strtoupper((string) $id))
                ->all();

            if ($pointers !== []) {
                $connection->table('purchase_request_items')
                    ->where('purchase_order_item_id', $lineId)
                    ->update(['purchase_order_item_id' => null]);
            }

            $connection->table('purchase_order_items')->where('id', $lineId)->delete();

            OrderLines::retotal($connection, $orderId, Money::of($locked->shipping), $actorId, $this->clock->timestamp());

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::ORDER_LINE_REMOVED,
                'purchase_order',
                $orderId,
                $before,
                $pointers === [] ? null : ['cleared_request_line_ids' => $pointers],
                $branchId,
            );

            return new RemovePurchaseOrderLineResult(
                $lineId,
                $orderId,
                $before,
                OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                    ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}].")),
                $pointers,
            );
        });
    }
}
