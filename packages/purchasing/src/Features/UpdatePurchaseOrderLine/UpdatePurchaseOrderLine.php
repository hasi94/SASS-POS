<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseOrderLine;

/**
 * Edit a line of a purchase order (T-034 AC-42 to AC-49, AC-61, AC-63).
 */
final class UpdatePurchaseOrderLine
{
    /**
     * @param  array<string, mixed>  $fields  `order_id`, `line_id`, and any of `quantity`, `unit_id`, `unit_cost`, `discount_percent`, `tax_group_id`, `description`, `expected_date`; a field left out keeps its value
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
