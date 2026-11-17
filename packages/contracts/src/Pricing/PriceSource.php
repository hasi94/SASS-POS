<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

use InvalidArgumentException;

/**
 * Where a resolved unit price came from (AC-15, AC-16, plan Ruling 2).
 *
 * `step` is the ladder step, 1 to 6. Steps 1 to 4 come from a price list and
 * name it; steps 5 and 6 come from the product and name none. The basis is
 * `product` exactly for steps 5 and 6.
 */
final readonly class PriceSource
{
    public ?string $priceListId;

    public function __construct(
        public int $step,
        ?string $priceListId,
        public PriceBasis $basis,
    ) {
        if ($step < 1 || $step > 6) {
            throw new InvalidArgumentException('[step] must be 1 to 6.');
        }

        $fromList = $step <= 4;

        if ($fromList && ($priceListId === null || $basis === PriceBasis::Product)) {
            throw new InvalidArgumentException('Steps 1 to 4 come from a price list: [priceListId] is required and [basis] is unit_matched or derived.');
        }

        if (! $fromList && ($priceListId !== null || $basis !== PriceBasis::Product)) {
            throw new InvalidArgumentException('Steps 5 and 6 come from the product: [priceListId] must be null and [basis] is product.');
        }

        $this->priceListId = $priceListId === null ? null : Shape::ulid($priceListId, 'priceListId');
    }

    /**
     * @return array{step: int, price_list_id: ?string, basis: string}
     */
    public function toArray(): array
    {
        return ['step' => $this->step, 'price_list_id' => $this->priceListId, 'basis' => $this->basis->value];
    }
}
