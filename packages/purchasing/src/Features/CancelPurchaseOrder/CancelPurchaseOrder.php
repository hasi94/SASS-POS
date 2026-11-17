<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CancelPurchaseOrder;

/**
 * Cancel a purchase order that has received nothing (T-034 AC-64).
 */
final class CancelPurchaseOrder
{
    /**
     * @param  array<string, mixed>  $fields  `order_id`, `reason` (1 to 500 characters; required for an approved order)
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
