<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\AddPurchaseOrderLine;

final class AddPurchaseOrderLineResult
{
    /**
     * @param  bool  $replayed  true when the line id already existed with identical input: nothing was written
     * @param  array<string, mixed>  $line  the stored line
     * @param  array<string, mixed>  $order  the stored header, totals included
     */
    public function __construct(
        public readonly string $lineId,
        public readonly string $orderId,
        public readonly bool $replayed,
        public readonly array $line,
        public readonly array $order,
    ) {}
}
