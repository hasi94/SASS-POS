<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\Shape;
use InvalidArgumentException;

/**
 * One `discounts` row as the pure engine sees it (AC-19, AC-20).
 *
 * `products`, `categories` and `brands` are the three lists of
 * `applies_to`. When **all three are empty** the discount applies to every
 * item; when any names an id, a line must be in at least one of them (OR). The
 * approval fields are deliberately absent: they are never evaluated (D-8).
 *
 * A percentage `value` above 100 is refused as `invalid_amount` here, so a
 * discount can never exceed the base it reduces.
 */
final readonly class DiscountRule
{
    public string $value;

    public ?string $minAmount;

    public ?string $maxDiscount;

    public ?string $startsAt;

    public ?string $endsAt;

    /**
     * @param  list<string>  $products
     * @param  list<string>  $categories
     * @param  list<string>  $brands
     *
     * @throws PricingRefused
     */
    public function __construct(
        public string $id,
        public DiscountType $type,
        string $value,
        public DiscountScope $scope,
        public bool $isActive,
        ?string $startsAt,
        ?string $endsAt,
        public ?int $usageLimit,
        public int $usedCount,
        ?string $minAmount,
        ?string $maxDiscount,
        public array $products = [],
        public array $categories = [],
        public array $brands = [],
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('[id] must not be blank.');
        }

        if ($usedCount < 0 || ($usageLimit !== null && $usageLimit < 0)) {
            throw new InvalidArgumentException('[usedCount] and [usageLimit] must not be negative.');
        }

        $this->value = Shape::money($value, 'value');
        $this->minAmount = $minAmount === null ? null : Shape::money($minAmount, 'minAmount');
        $this->maxDiscount = $maxDiscount === null ? null : Shape::money($maxDiscount, 'maxDiscount');
        $this->startsAt = $startsAt === null ? null : Shape::instant($startsAt, 'startsAt');
        $this->endsAt = $endsAt === null ? null : Shape::instant($endsAt, 'endsAt');

        if ($type === DiscountType::Percentage && version_compare($this->value, '100.0000', '>')) {
            throw PricingRefused::invalidAmount('[value] a percentage discount must not exceed 100.');
        }
    }

    public function targetsEverything(): bool
    {
        return $this->products === [] && $this->categories === [] && $this->brands === [];
    }
}
