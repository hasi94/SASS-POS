<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ActivateSupplier;

/**
 * Let a deactivated supplier take new requests and orders again (T-034 AC-13).
 */
final class ActivateSupplier
{
    public function __construct(
        public readonly string $supplierId,
        public readonly ?string $actorId = null,
    ) {}
}
