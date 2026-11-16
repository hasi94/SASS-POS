<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\UpdateSaleLine;

/**
 * Re-quantity, re-unit or re-price a line of a draft sale (T-030 AC-6, AC-7, AC-55).
 */
final class UpdateSaleLine
{
    /**
     * @param  array<string, mixed>  $fields  `sale_id`, `line_id`; any of `quantity`, `unit_id`, `discount_id`, `unit_price` (an override; null clears it) and `approval_id`. A field left out keeps the line's stored value
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
