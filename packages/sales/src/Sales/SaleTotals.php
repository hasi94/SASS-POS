<?php

declare(strict_types=1);

namespace Flexipos\Sales\Sales;

/**
 * The four header figures {@see SaleRollup} re-sums from a sale's lines, each a
 * canonical 4-decimal string.
 */
final readonly class SaleTotals
{
    public function __construct(
        public string $subtotal,
        public string $discountTotal,
        public string $taxTotal,
        public string $total,
    ) {}

    /**
     * The `sales` columns, ready for an `update`.
     *
     * @return array{subtotal: string, discount_total: string, tax_total: string, total: string}
     */
    public function toColumns(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discountTotal,
            'tax_total' => $this->taxTotal,
            'total' => $this->total,
        ];
    }
}
