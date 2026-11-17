<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\RemovePurchaseOrderLine;

final class RemovePurchaseOrderLineResult
{
    /**
     * @param  array<string, mixed>  $line  the line as it was before it was deleted
     * @param  array<string, mixed>  $order  the stored header, totals included
     * @param  list<string>  $clearedRequestLineIds  the request lines whose pointer to this line was cleared (D-48)
     */
    public function __construct(
        public readonly string $lineId,
        public readonly string $orderId,
        public readonly array $line,
        public readonly array $order,
        public readonly array $clearedRequestLineIds,
    ) {}
}
