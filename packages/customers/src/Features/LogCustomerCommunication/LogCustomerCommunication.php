<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\LogCustomerCommunication;

/**
 * Log a customer communication (T-028 AC-35; plan Ruling 8).
 */
final class LogCustomerCommunication
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
