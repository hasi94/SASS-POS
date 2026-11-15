<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * The tax of one amount under one tax group (AC-11, plan Ruling 2).
 *
 * `net` is the tax-exclusive amount: the supplied amount itself for an
 * exclusive group, and the amount with the tax backed out for an inclusive one,
 * so `net + total` is the gross in both. `lines` holds one entry per member
 * that applied, in `sequence` order; an empty group gives no lines and a total
 * of `0.0000` (AC-9).
 *
 * {@see toArray()} is exactly the JSON `sale_items.tax_breakdown` stores.
 */
final readonly class TaxBreakdown
{
    public string $net;

    public string $total;

    /**
     * @param  list<TaxLine>  $lines
     */
    public function __construct(
        public bool $isInclusive,
        string $net,
        public array $lines,
        string $total,
    ) {
        $this->net = Shape::money($net, 'net');
        $this->total = Shape::money($total, 'total');
    }

    /**
     * @return array{is_inclusive: bool, net: string, lines: list<array{tax_id: string, code: string, tax_code: ?string, type: string, is_compound: bool, rate: string, base: string, amount: string}>, total: string}
     */
    public function toArray(): array
    {
        return [
            'is_inclusive' => $this->isInclusive,
            'net' => $this->net,
            'lines' => array_map(static fn (TaxLine $line): array => $line->toArray(), $this->lines),
            'total' => $this->total,
        ];
    }
}
