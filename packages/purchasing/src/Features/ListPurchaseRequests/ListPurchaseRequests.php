<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ListPurchaseRequests;

/**
 * List purchase requests, newest first, keyset-paged (T-034 AC-35, D-34).
 */
final class ListPurchaseRequests
{
    /**
     * @param  mixed  $status  null for any, otherwise one of the six request statuses
     * @param  mixed  $branchId  null for every branch the caller may see, otherwise one branch
     * @param  mixed  $number  null for any, otherwise a document number
     * @param  mixed  $pageSize  null for the default, otherwise an int of at least 1 (above the maximum is clamped)
     * @param  mixed  $beforeId  the last id of the previous page
     */
    public function __construct(
        public readonly ?string $actorId = null,
        public readonly mixed $status = null,
        public readonly mixed $branchId = null,
        public readonly mixed $number = null,
        public readonly mixed $pageSize = null,
        public readonly mixed $beforeId = null,
    ) {}
}
