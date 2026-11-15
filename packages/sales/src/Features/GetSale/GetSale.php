<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\GetSale;

/**
 * One sale: its header and its tenders (T-030 D-26). Its lines are `ListSaleLines`.
 */
final class GetSale
{
    public function __construct(public readonly string $saleId) {}
}
