<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\ListCustomerNotes;

/**
 * List one customer's notes, newest first, keyset-paged (T-028 AC-37; plan
 * Ruling 11).
 */
final class ListCustomerNotes
{
    public function __construct(
        public readonly string $customerId,
        public readonly ?int $pageSize = null,
        public readonly ?string $beforeId = null,
    ) {}
}
