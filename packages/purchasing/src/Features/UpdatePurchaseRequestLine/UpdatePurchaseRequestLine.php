<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseRequestLine;

/**
 * Edit a line of a draft purchase request (T-034 AC-22 to AC-25, AC-39).
 */
final class UpdatePurchaseRequestLine
{
    /**
     * @param  array<string, mixed>  $fields  `request_id`, `line_id`, and any of `quantity`, `unit_id`, `estimated_cost` (null clears it) and `supplier_id` (null clears it); the product of a line never changes
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
