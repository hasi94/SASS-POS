<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CancelPurchaseOrder;

final class CancelPurchaseOrderResult
{
    /**
     * @param  bool  $changed  false when the order was already cancelled: nothing was written
     */
    public function __construct(
        public readonly string $orderId,
        public readonly bool $changed,
    ) {}
}
