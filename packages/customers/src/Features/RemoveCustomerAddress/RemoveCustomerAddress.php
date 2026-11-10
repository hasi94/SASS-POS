<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\RemoveCustomerAddress;

/**
 * Remove a customer address (T-028 AC-18; plan Ruling 8, D-16).
 */
final class RemoveCustomerAddress
{
    public function __construct(
        public readonly string $addressId,
        public readonly ?string $actorId = null,
    ) {}
}
