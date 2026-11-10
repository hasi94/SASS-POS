<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\CreateCustomerGroup;

final readonly class CreateCustomerGroupResult
{
    public function __construct(
        public string $groupId,
    ) {}
}
