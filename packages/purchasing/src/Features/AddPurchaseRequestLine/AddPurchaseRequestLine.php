<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\AddPurchaseRequestLine;

/**
 * Add a line to a draft purchase request (T-034 AC-22, AC-23, AC-25, AC-39).
 */
final class AddPurchaseRequestLine
{
    /**
     * @param  array<string, mixed>  $fields  `id` (the caller's ULID, also the replay key), `request_id`, `product_id`, `unit_id`, `quantity`, and optionally `estimated_cost` and `supplier_id`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
