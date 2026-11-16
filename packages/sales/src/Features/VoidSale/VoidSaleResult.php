<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\VoidSale;

final class VoidSaleResult
{
    /**
     * @param  list<string>  $mirrorTenderIds  one per original tender, in the originals' order
     */
    public function __construct(
        public readonly string $saleId,
        public readonly array $mirrorTenderIds,
    ) {}
}
