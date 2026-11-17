<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ListCashShifts;

/**
 * List cash shifts, newest first, keyset-paged (T-030 D-26), optionally for one
 * branch, one terminal and one status.
 */
final class ListCashShifts
{
    public function __construct(
        public readonly ?string $branchId = null,
        public readonly ?string $terminalId = null,
        public readonly ?string $status = null,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
