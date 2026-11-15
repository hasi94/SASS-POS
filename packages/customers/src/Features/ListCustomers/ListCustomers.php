<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListCustomers;

/**
 * List customers, excluding soft-deleted rows, filtered by group and
 * status, keyset-paged (T-028 plan Ruling 11).
 */
final class ListCustomers
{
    public function __construct(
        public readonly ?string $groupId = null,
        public readonly ?string $status = null,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
