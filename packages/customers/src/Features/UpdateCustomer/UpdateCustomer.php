<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\UpdateCustomer;

/**
 * Update a customer (T-028 AC-4, AC-6a, AC-8, AC-9, AC-12; plan Ruling 7).
 */
final class UpdateCustomer
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $customerId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
