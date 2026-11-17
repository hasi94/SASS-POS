<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RefundSale;

/**
 * Refund a sale, or give goodwill with no original (T-030 AC-25 to AC-31).
 */
final class RefundSale
{
    /**
     * @param  array<string, mixed>  $fields  `terminal_id`, `reference_sale_id` (absent for goodwill), `customer_id` (goodwill only), `lines`, `tenders`, `approval_id`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
