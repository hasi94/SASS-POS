<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\GetPurchaseOrder;

/**
 * One purchase order with its lines (T-034 AC-81, AC-83).
 */
final class GetPurchaseOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly ?string $actorId = null,
    ) {}
}
