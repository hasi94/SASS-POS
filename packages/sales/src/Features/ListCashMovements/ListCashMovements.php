<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ListCashMovements;

/**
 * List one shift's cash movements, newest first, keyset-paged (T-030 D-26, R-17).
 */
final class ListCashMovements
{
    public function __construct(
        public readonly string $shiftId,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
