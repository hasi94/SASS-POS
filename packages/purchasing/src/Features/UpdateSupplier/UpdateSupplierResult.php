<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdateSupplier;

final class UpdateSupplierResult
{
    /**
     * @param  array<string, mixed>  $supplier  the stored row, `bank_details` included (the caller holds `manage`)
     * @param  list<string>  $changedColumns  what this call changed; empty when it changed nothing
     */
    public function __construct(
        public readonly string $supplierId,
        public readonly array $supplier,
        public readonly array $changedColumns,
    ) {}

    public function changed(): bool
    {
        return $this->changedColumns !== [];
    }
}
