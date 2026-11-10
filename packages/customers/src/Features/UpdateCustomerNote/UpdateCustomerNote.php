<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\UpdateCustomerNote;

/**
 * Update a customer note (T-028 AC-34; plan Ruling 10).
 */
final class UpdateCustomerNote
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $noteId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
