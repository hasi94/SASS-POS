<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CreatePurchaseOrder;

final class CreatePurchaseOrderResult
{
    /**
     * @param  bool  $replayed  true when the id already existed with identical input: nothing was written
     * @param  array<string, mixed>  $order  the stored header
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $number,
        public readonly bool $replayed,
        public readonly array $order,
    ) {}
}
