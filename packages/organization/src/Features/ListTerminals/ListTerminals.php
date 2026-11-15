<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListTerminals;

/**
 * The terminals, optionally narrowed by branch and status (T-014 AC-42).
 */
final class ListTerminals
{
    public function __construct(
        public readonly ?int $pageSize = null,
        public readonly mixed $branchId = null,
        public readonly mixed $status = null,
    ) {}
}
