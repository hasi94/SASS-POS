<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListCustomerAddresses;

/**
 * List one customer's addresses, newest first, keyset-paged (T-028 plan
 * Ruling 11).
 */
final class ListCustomerAddresses
{
    public function __construct(
        public readonly string $customerId,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
