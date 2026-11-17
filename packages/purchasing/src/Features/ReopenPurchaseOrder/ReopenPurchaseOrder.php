<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ReopenPurchaseOrder;

/**
 * Reopen an approved order with nothing received (T-034 AC-62).
 */
final class ReopenPurchaseOrder
{
    /**
     * @param  array<string, mixed>  $fields  `order_id`, `reason` (1 to 500 characters)
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
