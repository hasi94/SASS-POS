<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\CreateCustomerGroup;

/**
 * Create a customer group (T-028 AC-1 to AC-2, AC-5; plan Ruling 7).
 */
final class CreateCustomerGroup
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
