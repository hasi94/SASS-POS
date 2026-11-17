<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseOrder;

final class UpdatePurchaseOrderResult
{
    /**
     * @param  array<string, mixed>  $order  the stored header
     * @param  list<string>  $changedColumns  what this call changed, the derived snapshot and total columns included; empty when it changed nothing
     */
    public function __construct(
        public readonly string $orderId,
        public readonly array $order,
        public readonly array $changedColumns,
    ) {}

    public function changed(): bool
    {
        return $this->changedColumns !== [];
    }
}
