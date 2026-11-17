<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ClosePurchaseOrder;

final class ClosePurchaseOrderResult
{
    /**
     * @param  bool  $changed  false when the order was already closed: nothing was written
     * @param  array<string, mixed>  $order  the stored header
     */
    public function __construct(
        public readonly string $orderId,
        public readonly bool $changed,
        public readonly array $order,
    ) {}
}
