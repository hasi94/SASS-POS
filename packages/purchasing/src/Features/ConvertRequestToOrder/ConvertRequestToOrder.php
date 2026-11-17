<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ConvertRequestToOrder;

/**
 * Turn an approved purchase request into a draft purchase order for one supplier
 * (T-034 AC-30 to AC-33, AC-94).
 */
final class ConvertRequestToOrder
{
    /**
     * @param  array<string, mixed>  $fields  `request_id` and `supplier_id`; the order id, its number and every line are the server's
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
