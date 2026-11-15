<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\AddCustomerNote;

/**
 * Add a customer note (T-028 AC-34; plan Ruling 10).
 */
final class AddCustomerNote
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
