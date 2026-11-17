<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ListPurchaseOrders;

/**
 * List purchase orders, newest first, keyset-paged (T-034 AC-82, D-34).
 */
final class ListPurchaseOrders
{
    /**
     * @param  mixed  $status  null for any, otherwise one of the eight order statuses
     * @param  mixed  $supplierId  null for any, otherwise a supplier id
     * @param  mixed  $branchId  null for every branch the caller may see, otherwise one branch
     * @param  mixed  $number  null for any, otherwise a document number
     * @param  mixed  $orderDateFrom  null, or the first order date to list (`Y-m-d`, inclusive)
     * @param  mixed  $orderDateTo  null, or the last order date to list (`Y-m-d`, inclusive); never before the first
     * @param  mixed  $pageSize  null for the default, otherwise an int of at least 1 (above the maximum is clamped)
     * @param  mixed  $beforeId  the last id of the previous page
     */
    public function __construct(
        public readonly ?string $actorId = null,
        public readonly mixed $status = null,
        public readonly mixed $supplierId = null,
        public readonly mixed $branchId = null,
        public readonly mixed $number = null,
        public readonly mixed $orderDateFrom = null,
        public readonly mixed $orderDateTo = null,
        public readonly mixed $pageSize = null,
        public readonly mixed $beforeId = null,
    ) {}
}
