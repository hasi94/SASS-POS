<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\DeleteCustomer;

/**
 * Soft-delete a customer (T-028 AC-10; plan Ruling 7).
 */
final class DeleteCustomer
{
    public function __construct(
        public readonly string $customerId,
        public readonly ?string $actorId = null,
    ) {}
}
