<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\PriceSource;
use Flexipos\Contracts\Pricing\Shape;

/**
 * What the ladder resolved for one line (AC-15, AC-16a).
 *
 * `listPrice` is the typed or derived price, unchanged. `unitPrice` is that
 * price after the winning item's `discountPercent`, when it has one. They map
 * onto `sale_items.list_price` and `unit_price`.
 */
final readonly class LadderOutcome
{
    public string $listPrice;

    public ?string $discountPercent;

    public string $unitPrice;

    public function __construct(
        string $listPrice,
        ?string $discountPercent,
        string $unitPrice,
        public PriceSource $source,
    ) {
        $this->listPrice = Shape::money($listPrice, 'listPrice');
        $this->discountPercent = $discountPercent === null ? null : Shape::rate($discountPercent, 'discountPercent');
        $this->unitPrice = Shape::money($unitPrice, 'unitPrice');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'list_price' => $this->listPrice,
            'discount_percent' => $this->discountPercent,
            'unit_price' => $this->unitPrice,
            'source' => $this->source->toArray(),
        ];
    }
}
