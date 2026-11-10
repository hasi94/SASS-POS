<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\LogCustomerCommunication;

final readonly class LogCustomerCommunicationResult
{
    public function __construct(
        public string $communicationId,
    ) {}
}
