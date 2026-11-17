<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseOrder;

/**
 * Edit the header of a purchase order (T-034 AC-41, AC-50, AC-60, AC-61, AC-63).
 */
final class UpdatePurchaseOrder
{
    /**
     * @param  array<string, mixed>  $fields  `order_id`, and any of `supplier_id`, `location_id`, `expected_date`, `currency`, `exchange_rate`, `shipping`, `terms`, `notes`; a field left out keeps its value
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
