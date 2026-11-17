<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RefundSale;

final class RefundSaleResult
{
    /**
     * @param  list<string>  $tenderIds  the refund's tenders, in the order they were given
     * @param  string|null  $originalStatus  the original sale's status after this refund; null for a goodwill refund
     */
    public function __construct(
        public readonly string $refundId,
        public readonly string $number,
        public readonly string $total,
        public readonly array $tenderIds,
        public readonly ?string $originalStatus,
    ) {}
}
