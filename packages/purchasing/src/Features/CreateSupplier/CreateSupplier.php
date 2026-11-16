<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CreateSupplier;

/**
 * Create a supplier (T-034 AC-1 to AC-9, AC-17).
 */
final class CreateSupplier
{
    /**
     * @param  array<string, mixed>  $fields  `code` and `name`, and any of the optional columns; never an `id` (the server names the supplier)
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
