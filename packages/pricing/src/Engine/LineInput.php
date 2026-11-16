<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\Shape;
use InvalidArgumentException;

/**
 * Everything the pure line calculation needs (AC-22, plan Ruling 6): the ladder
 * input (which carries the line unit, its factor and the base quantity), the
 * **line-unit** quantity the subtotal multiplies by, the product facts, the
 * requested discount and the candidate rules, and the tax group.
 *
 * `quantity` is `UnitSnapshot::$quantity`, in the line's own unit. It is not
 * `ladder->baseQuantity`: `unit_price` is per line unit, so the subtotal is
 * `unitPrice x quantity` (D-12). `discountId` null means no discount; a
 * non-null id that is in none of `discounts` is `unknown_discount`.
 */
final readonly class LineInput
{
    public string $quantity;

    public string $documentDate;

    /**
     * @param  list<TaxMember>  $taxMembers
     * @param  list<DiscountRule>  $discounts
     */
    public function __construct(
        public LadderInput $ladder,
        string $quantity,
        public ProductFacts $product,
        public string $taxGroupId,
        public bool $taxGroupIsInclusive,
        public array $taxMembers,
        string $documentDate,
        public ?string $discountId = null,
        public array $discounts = [],
    ) {
        if ($taxGroupId === '') {
            throw new InvalidArgumentException('[taxGroupId] must not be blank.');
        }

        $this->quantity = Shape::money($quantity, 'quantity');
        $this->documentDate = Shape::date($documentDate, 'documentDate');
    }
}
