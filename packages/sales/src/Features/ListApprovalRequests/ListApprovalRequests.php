<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ListApprovalRequests;

/**
 * List approval requests, newest first, keyset-paged (T-030 D-26), optionally
 * for one branch and one status.
 */
final class ListApprovalRequests
{
    public function __construct(
        public readonly ?string $branchId = null,
        public readonly ?string $status = null,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
