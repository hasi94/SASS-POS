<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ListSales;

/**
 * List a branch's sales, newest first, keyset-paged (T-030 AC-20, D-26).
 */
final class ListSales
{
    public function __construct(
        public readonly string $branchId,
        public readonly ?string $status = null,
        public readonly ?string $type = null,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
