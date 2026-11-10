<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\CreateCustomer;

/**
 * Create a customer (T-028 AC-6, AC-6a, AC-7 to AC-12, AC-7a; plan Ruling 7).
 */
final class CreateCustomer
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
