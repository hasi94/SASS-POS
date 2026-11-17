<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ReopenPurchaseOrder;

final class ReopenPurchaseOrderResult
{
    /**
     * @param  string|null  $previousInstanceId  the approval instance the order held before it went back to draft
     * @param  array<string, mixed>  $order  the stored header
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $status,
        public readonly ?string $previousInstanceId,
        public readonly array $order,
    ) {}
}
