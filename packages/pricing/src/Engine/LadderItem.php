<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\Shape;
use InvalidArgumentException;

/**
 * One `price_list_items` row for the product being priced (AC-13).
 *
 * A null `unitId` means "priced per stock unit, derive by the line's factor".
 * `minQty` is a **base**-quantity threshold on both branches (AC-16).
 * `discountPercent`, when set, is applied by the ladder (AC-16a).
 */
final readonly class LadderItem
{
    public string $minQty;

    public string $price;

    public ?string $discountPercent;

    public function __construct(
        public ?string $unitId,
        string $minQty,
        string $price,
        ?string $discountPercent = null,
    ) {
        $this->minQty = Shape::money($minQty, 'minQty');
        $this->price = Shape::money($price, 'price');
        $this->discountPercent = $discountPercent === null ? null : Shape::rate($discountPercent, 'discountPercent');

        if ($this->discountPercent !== null && version_compare($this->discountPercent, '100.0000', '>')) {
            throw new InvalidArgumentException('[discountPercent] must not exceed 100.');
        }
    }
}
