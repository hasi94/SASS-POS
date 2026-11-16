<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * A bill-scope discount computed once against the summed discounted subtotals
 * (AC-23, D-19). Allocating it back across lines, and re-taxing each line, is
 * the future Sales caller's job.
 */
final readonly class BillDiscount
{
    public string $discountId;

    public string $base;

    public string $amount;

    public function __construct(string $discountId, string $base, string $amount)
    {
        $this->discountId = Shape::ulid($discountId, 'discountId');
        $this->base = Shape::money($base, 'base');
        $this->amount = Shape::money($amount, 'amount');
    }

    /**
     * @return array{discount_id: string, base: string, amount: string}
     */
    public function toArray(): array
    {
        return ['discount_id' => $this->discountId, 'base' => $this->base, 'amount' => $this->amount];
    }
}
