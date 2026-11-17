<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ListPurchaseOrders;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Purchasing\Documents\BranchScope;
use Flexipos\Purchasing\Documents\DateInput;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Page;
use Flexipos\Purchasing\Support\Pager;
use Flexipos\Purchasing\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * List orders (T-034 AC-82, AC-84, D-34, D-36).
 *
 * Only orders of branches where the caller holds `purchasing.orders.view` appear
 * ({@see BranchScope}: one decision at the filtered branch, else one per branch row,
 * none allowed an empty page). Newest first by id, keyset-paged with `before_id`; a
 * `page_size` above the maximum is clamped, a non-positive or non-integer one and a
 * malformed cursor are `validation`. The filters (`status`, `supplier_id`,
 * `branch_id`, `number` and the inclusive `order_date_from` / `order_date_to` range)
 * combine; an unknown status, a malformed id or date, and a range whose end precedes
 * its start are `validation`, never an empty list. A cursor past the end or no match
 * is an empty page. Every bad input is refused before the branch scope is applied,
 * so a caller who may see nothing still learns their request was malformed.
 */
final class ListPurchaseOrdersHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly PermissionResolver $permissions,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(ListPurchaseOrders $query): Page
    {
        $connection = $this->tenant->get();

        $status = $this->status($query->status);
        $supplierId = Identifier::foldNullable($query->supplierId, 'supplier_id');
        $number = $this->number($query->number);
        $from = DateInput::optional($query->orderDateFrom, 'order_date_from');
        $to = DateInput::optional($query->orderDateTo, 'order_date_to');

        if ($from !== null && $to !== null && $to < $from) {
            throw PurchasingRefused::validation('The order_date_to is before the order_date_from.');
        }

        $allowed = BranchScope::allowed($connection, $this->permissions, $query->actorId, PurchasingPermissions::ORDERS_VIEW, $query->branchId);

        // Validate the paging even when nothing may be shown, so a bad cursor is never silently an empty page.
        Pager::size($this->config, $query->pageSize);
        Identifier::foldNullable($query->beforeId, 'before_id');

        if ($allowed === []) {
            return new Page([], null);
        }

        $builder = $connection->table('purchase_orders')->whereIn('branch_id', $allowed);

        if ($status !== null) {
            $builder->where('status', $status);
        }

        if ($supplierId !== null) {
            $builder->where('supplier_id', $supplierId);
        }

        if ($number !== null) {
            $builder->where('number', $number);
        }

        if ($from !== null) {
            $builder->where('order_date', '>=', $from);
        }

        if ($to !== null) {
            $builder->where('order_date', '<=', $to);
        }

        $page = Pager::page($builder, $this->config, $query->pageSize, $query->beforeId);

        return new Page(
            array_map(static fn (array $row): array => OrderImage::header($row), $page->rows),
            $page->nextCursor,
        );
    }

    /**
     * @throws PurchasingRefused validation
     */
    private function status(mixed $submitted): ?string
    {
        if ($submitted === null) {
            return null;
        }

        $statuses = array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases());

        if (! is_string($submitted) || ! in_array($submitted, $statuses, true)) {
            throw PurchasingRefused::validation('The status must be one of: '.implode(', ', $statuses).'.');
        }

        return $submitted;
    }

    /**
     * @throws PurchasingRefused validation
     */
    private function number(mixed $submitted): ?string
    {
        if ($submitted === null) {
            return null;
        }

        if (! is_string($submitted) || trim($submitted) === '' || mb_strlen(trim($submitted)) > 30) {
            throw PurchasingRefused::validation('The number must be a document number of at most 30 characters.');
        }

        return trim($submitted);
    }
}
