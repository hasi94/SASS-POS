<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseOrderLine;

final class UpdatePurchaseOrderLineResult
{
    /**
     * @param  array<string, mixed>  $line  the stored line
     * @param  array<string, mixed>  $order  the stored header, totals included
     * @param  list<string>  $changedColumns  what this call changed; empty when it changed nothing
     */
    public function __construct(
        public readonly string $lineId,
        public readonly string $orderId,
        public readonly array $line,
        public readonly array $order,
        public readonly array $changedColumns,
    ) {}

    public function changed(): bool
    {
        return $this->changedColumns !== [];
    }
}
