<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SubmitPurchaseOrder;

/**
 * Submit a draft purchase order for approval (T-034 AC-54 to AC-58).
 */
final class SubmitPurchaseOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly ?string $actorId = null,
    ) {}
}
