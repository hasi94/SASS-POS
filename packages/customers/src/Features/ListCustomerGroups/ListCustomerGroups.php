<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListCustomerGroups;

/**
 * List customer groups, excluding soft-deleted rows, keyset-paged
 * (T-028 plan Ruling 11).
 */
final class ListCustomerGroups
{
    public function __construct(
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
