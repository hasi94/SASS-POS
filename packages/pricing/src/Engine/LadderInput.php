<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\Shape;

/**
 * Everything the pure price ladder needs and nothing it must look up (plan
 * Ruling 5): the line's unit facts exactly as `UnitSnapshot` carries them, the
 * document instant and currency, the line's three scope ids, the candidate
 * lists, and the two product prices (both **per stock unit**, D-13).
 *
 * `unitFactor` is the caller's snapshotted factor and is never re-resolved
 * (Ruling 4). The two selling prices are null when the row does not exist or
 * holds no override.
 */
final readonly class LadderInput
{
    public string $unitFactor;

    public string $baseQuantity;

    public string $at;

    public string $currency;

    public ?string $branchSellingPrice;

    public ?string $sellingPrice;

    /**
     * @param  list<LadderList>  $lists
     */
    public function __construct(
        public string $unitId,
        string $unitFactor,
        string $baseQuantity,
        string $at,
        string $currency,
        public ?string $customerId,
        public ?string $customerGroupId,
        public ?string $branchId,
        public array $lists,
        ?string $branchSellingPrice,
        ?string $sellingPrice,
    ) {
        $this->unitFactor = Shape::factor($unitFactor, 'unitFactor');
        $this->baseQuantity = Shape::money($baseQuantity, 'baseQuantity');
        $this->at = Shape::instant($at, 'at');
        $this->currency = Shape::currency($currency, 'currency');
        $this->branchSellingPrice = $branchSellingPrice === null ? null : Shape::money($branchSellingPrice, 'branchSellingPrice');
        $this->sellingPrice = $sellingPrice === null ? null : Shape::money($sellingPrice, 'sellingPrice');
    }
}
