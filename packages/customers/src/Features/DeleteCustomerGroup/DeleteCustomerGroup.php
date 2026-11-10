<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\DeleteCustomerGroup;

/**
 * Soft-delete a customer group (T-028 AC-3; plan Ruling 7).
 */
final class DeleteCustomerGroup
{
    public function __construct(
        public readonly string $groupId,
        public readonly ?string $actorId = null,
    ) {}
}
