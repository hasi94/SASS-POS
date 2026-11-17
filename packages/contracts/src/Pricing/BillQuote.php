<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * What a caller asks `PricingEngine::billDiscount()` to compute (AC-23, D-19).
 *
 * `discountedSubtotals` are each line's post-item-discount, **pre-tax** amount
 * (`PricedLine::$discountedSubtotal`), never `lineTotal`: a bill discount
 * applied to a total that includes exclusive tax would discount the tax too.
 */
final readonly class BillQuote
{
    public string $discountId;

    public string $at;

    /** @var list<string> */
    public array $discountedSubtotals;

    /**
     * @param  list<string>  $discountedSubtotals
     */
    public function __construct(string $discountId, string $at, array $discountedSubtotals)
    {
        $this->discountId = Shape::ulid($discountId, 'discountId');
        $this->at = Shape::instant($at, 'at');
        $checked = [];

        foreach ($discountedSubtotals as $value) {
            $checked[] = Shape::money($value, 'discountedSubtotals');
        }

        $this->discountedSubtotals = $checked;
    }
}
