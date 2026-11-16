<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\DeleteSupplier;

final class DeleteSupplierResult
{
    /**
     * @param  bool  $changed  false when the supplier was already deleted (an idempotent repeat: no audit row)
     */
    public function __construct(
        public readonly string $supplierId,
        public readonly bool $changed,
    ) {}
}
