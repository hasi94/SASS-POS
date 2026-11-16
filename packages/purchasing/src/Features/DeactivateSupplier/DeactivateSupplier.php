<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\DeactivateSupplier;

/**
 * Stop a supplier taking new requests and orders (T-034 AC-13).
 */
final class DeactivateSupplier
{
    public function __construct(
        public readonly string $supplierId,
        public readonly ?string $actorId = null,
    ) {}
}
