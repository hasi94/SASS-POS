<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\GetSupplier;

/**
 * One supplier (T-034 AC-15, AC-18).
 */
final class GetSupplier
{
    public function __construct(
        public readonly string $supplierId,
        public readonly ?string $actorId = null,
    ) {}
}
