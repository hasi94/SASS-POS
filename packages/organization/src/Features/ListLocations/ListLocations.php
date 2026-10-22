<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListLocations;

/**
 * The locations, optionally narrowed by branch and by type (T-014 AC-25).
 *
 * The filters are taken as submitted so the handler can refuse a malformed
 * branch id and an unknown type as `validation`.
 */
final class ListLocations
{
    public function __construct(
        public readonly ?int $pageSize = null,
        public readonly mixed $branchId = null,
        public readonly mixed $type = null,
    ) {}
}
