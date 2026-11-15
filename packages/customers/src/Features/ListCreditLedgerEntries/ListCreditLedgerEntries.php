<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListCreditLedgerEntries;

/**
 * List one customer's `customer_ledger` rows, newest first, keyset-paged
 * (T-028 plan Ruling 11).
 */
final class ListCreditLedgerEntries
{
    public function __construct(
        public readonly string $customerId,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
