<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListCustomerCommunications;

/**
 * List one customer's communications, newest first, keyset-paged (T-028
 * AC-37; plan Ruling 11).
 */
final class ListCustomerCommunications
{
    public function __construct(
        public readonly string $customerId,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
