<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\CreateCustomer;

final readonly class CreateCustomerResult
{
    public function __construct(
        public string $customerId,
    ) {}
}
