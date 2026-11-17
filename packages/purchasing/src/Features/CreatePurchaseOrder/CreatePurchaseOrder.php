<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CreatePurchaseOrder;

/**
 * Raise a draft purchase order (T-034 AC-40, AC-41, AC-53, AC-90).
 */
final class CreatePurchaseOrder
{
    /**
     * @param  array<string, mixed>  $fields  `id` (the caller's ULID, which is also the replay key), `supplier_id`, `branch_id`, `location_id`, and optionally `expected_date`, `currency`, `exchange_rate`, `shipping`, `terms` and `notes`; the number, the status, the order date, the totals and the supplier snapshots are the server's
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
