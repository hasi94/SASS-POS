<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\AddCustomerAddress;

/**
 * Add a customer address (T-028 AC-16, AC-17; plan Ruling 8).
 */
final class AddCustomerAddress
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
