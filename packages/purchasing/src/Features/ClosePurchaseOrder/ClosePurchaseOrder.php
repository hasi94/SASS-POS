<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ClosePurchaseOrder;

/**
 * Close a partly or fully received order (T-034 AC-65).
 */
final class ClosePurchaseOrder
{
    /**
     * @param  array<string, mixed>  $fields  `order_id`, `reason` (1 to 500 characters)
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
