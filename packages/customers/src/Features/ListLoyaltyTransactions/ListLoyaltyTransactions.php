<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListLoyaltyTransactions;

/**
 * List one customer's `loyalty_transactions` rows, newest first, keyset-paged
 * (T-028 plan Ruling 11).
 */
final class ListLoyaltyTransactions
{
    public function __construct(
        public readonly string $customerId,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
