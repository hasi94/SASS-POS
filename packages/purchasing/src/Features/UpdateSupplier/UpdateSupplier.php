<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdateSupplier;

/**
 * Change some fields of a supplier (T-034 AC-4 to AC-6, AC-10 to AC-12, AC-17).
 */
final class UpdateSupplier
{
    /**
     * @param  array<string, mixed>  $fields  `supplier_id`, and any of the supplier columns; a field left out keeps its stored value, a `code` must equal the stored one
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
