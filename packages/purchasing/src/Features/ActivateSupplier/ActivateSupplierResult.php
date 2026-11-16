<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ActivateSupplier;

final class ActivateSupplierResult
{
    /**
     * @param  bool  $changed  false when the supplier was already active (an idempotent repeat: no audit row)
     */
    public function __construct(
        public readonly string $supplierId,
        public readonly bool $changed,
    ) {}
}
