<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\GetSupplier;

final class GetSupplierResult
{
    /**
     * @param  array<string, mixed>  $supplier  every column; `bank_details` only when the reader holds `manage`
     */
    public function __construct(public readonly array $supplier) {}
}
