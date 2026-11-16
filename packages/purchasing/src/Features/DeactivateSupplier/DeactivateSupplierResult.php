<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\DeactivateSupplier;

final class DeactivateSupplierResult
{
    /**
     * @param  bool  $changed  false when the supplier was already inactive (an idempotent repeat: no audit row)
     */
    public function __construct(
        public readonly string $supplierId,
        public readonly bool $changed,
    ) {}
}
