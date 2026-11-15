<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\UpdateCustomerGroup;

/**
 * Update a customer group (T-028 AC-1, AC-2, AC-4, AC-5; plan Ruling 7).
 */
final class UpdateCustomerGroup
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $groupId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
