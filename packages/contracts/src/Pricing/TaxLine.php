<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * One member tax's contribution to a breakdown (AC-11.7): the entry shape
 * `sale_items.tax_breakdown` snapshots.
 *
 * `taxCode` is the printed code and may be null. `rate` is the resolved
 * percentage (`15.0000` means 15 %) or, for a `fixed` tax, the amount per stock
 * unit. `base` is the amount the tax was computed on (the line amount for a
 * `fixed` tax). In an inclusive group the last percentage member whose rate is
 * not zero carries the rounding residue in its `amount` (AC-11.6, D-34), so it
 * can differ from `round(base x rate / 100)` by the odd 0.0001. A member at a
 * zero rate never does: its `amount` is always `0.0000`.
 */
final readonly class TaxLine
{
    public string $taxId;

    public string $rate;

    public string $base;

    public string $amount;

    public function __construct(
        string $taxId,
        public string $code,
        public ?string $taxCode,
        public string $type,
        public bool $isCompound,
        string $rate,
        string $base,
        string $amount,
    ) {
        $this->taxId = Shape::ulid($taxId, 'taxId');
        $this->rate = Shape::rate($rate, 'rate');
        $this->base = Shape::money($base, 'base');
        $this->amount = Shape::money($amount, 'amount');
    }

    /**
     * @return array{tax_id: string, code: string, tax_code: ?string, type: string, is_compound: bool, rate: string, base: string, amount: string}
     */
    public function toArray(): array
    {
        return [
            'tax_id' => $this->taxId,
            'code' => $this->code,
            'tax_code' => $this->taxCode,
            'type' => $this->type,
            'is_compound' => $this->isCompound,
            'rate' => $this->rate,
            'base' => $this->base,
            'amount' => $this->amount,
        ];
    }
}
