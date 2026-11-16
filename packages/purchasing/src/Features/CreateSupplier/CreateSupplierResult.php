<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CreateSupplier;

final class CreateSupplierResult
{
    /**
     * @param  array<string, mixed>  $supplier  the stored row, `bank_details` included (the caller holds `manage`)
     */
    public function __construct(
        public readonly string $supplierId,
        public readonly array $supplier,
    ) {}
}
