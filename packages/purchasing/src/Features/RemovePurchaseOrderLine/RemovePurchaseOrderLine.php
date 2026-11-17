<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\RemovePurchaseOrderLine;

/**
 * Remove a line from a draft purchase order (T-034 AC-49).
 */
final class RemovePurchaseOrderLine
{
    /**
     * @param  array<string, mixed>  $fields  `order_id` and `line_id`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
