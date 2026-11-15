<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\AddCustomerAddress;

final readonly class AddCustomerAddressResult
{
    public function __construct(
        public string $addressId,
    ) {}
}
