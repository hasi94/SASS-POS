<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ListSaleLines;

/**
 * List a sale's lines, newest first, keyset-paged (T-030 D-26).
 */
final class ListSaleLines
{
    public function __construct(
        public readonly string $saleId,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
