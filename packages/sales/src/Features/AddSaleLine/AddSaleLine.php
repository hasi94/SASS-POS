<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\AddSaleLine;

/**
 * Add a priced line to a draft sale (T-030 AC-2 to AC-7, AC-23a).
 */
final class AddSaleLine
{
    /**
     * @param  array<string, mixed>  $fields  `sale_id`, `product_id`, `unit_id`, `quantity`; optional `discount_id`, `unit_price` (a price override), `approval_id`, `employee_id` (the line's salesperson) and `notes`. Every price, factor and tax figure is the engine's
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
