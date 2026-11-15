<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\UpdateCustomerAddress;

/**
 * Update a customer address (T-028 AC-16, AC-17; plan Ruling 8).
 */
final class UpdateCustomerAddress
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $addressId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
