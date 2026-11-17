<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\GetPurchaseOrder;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Read one order, header and lines (T-034 AC-48, AC-81, AC-83, AC-84, D-36).
 *
 * The caller needs `purchasing.orders.view` **at the order's stored branch** (a
 * company-wide grant counts); an unknown id is `not_found`, and the id is looked up
 * first to learn its branch (the house shape for every `Get`). **Nothing is
 * recomputed on read** (AC-48): the lines are returned as they were frozen, the unit
 * quad, `tax_amount`, `line_total` and the header totals included, whatever a rate,
 * a group, a price, the ladder or the supplier has since become. The supplier is the
 * order's own snapshot, so an order of a soft-deleted supplier reads exactly as
 * before (AC-83): this slice never reads `suppliers`.
 */
final class GetPurchaseOrderHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(GetPurchaseOrder $query): GetPurchaseOrderResult
    {
        $orderId = Identifier::fold($query->orderId, 'order_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order');

        Authorise::require($this->permissions, $query->actorId, PurchasingPermissions::ORDERS_VIEW, strtoupper((string) $header->branch_id));

        return new GetPurchaseOrderResult(
            OrderImage::header($header),
            array_map(
                static fn (object $line): array => OrderImage::line($line),
                $connection->table('purchase_order_items')->where('order_id', $orderId)->orderBy('line_no')->get()->all(),
            ),
        );
    }
}
