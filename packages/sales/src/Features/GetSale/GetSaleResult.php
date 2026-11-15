<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\GetSale;

final class GetSaleResult
{
    /**
     * @param  array<string, mixed>  $sale
     * @param  list<array<string, mixed>>  $tenders
     */
    public function __construct(
        public readonly array $sale,
        public readonly array $tenders,
    ) {}
}
