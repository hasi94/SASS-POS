<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\DeleteSupplier;

/**
 * Soft-delete a supplier that no live document needs (T-034 AC-14).
 */
final class DeleteSupplier
{
    public function __construct(
        public readonly string $supplierId,
        public readonly ?string $actorId = null,
    ) {}
}
