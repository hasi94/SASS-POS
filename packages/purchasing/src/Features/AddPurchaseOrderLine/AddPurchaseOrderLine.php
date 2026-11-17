<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\AddPurchaseOrderLine;

/**
 * Add a line to a draft purchase order (T-034 AC-42 to AC-47, AC-51, AC-53).
 */
final class AddPurchaseOrderLine
{
    /**
     * @param  array<string, mixed>  $fields  `id` (the caller's ULID, which is also the replay key), `order_id`, `product_id`, `unit_id`, `quantity`, `unit_cost`, and optionally `discount_percent`, `tax_group_id`, `description` and `expected_date`; the unit quad, every amount and the line number are the server's
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
