<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * What a caller asks `PurchaseLinePricing::quotePurchaseLine()` to price (T-034
 * D-53a): one purchase line the caller has already resolved.
 *
 * `quantity` is in the line's own unit and `unitCost` is per that unit, so the
 * subtotal is their product. `baseQuantity` is the stock-unit quantity a
 * `fixed` tax is charged on. `discountPercent` is a percentage from `0.0000` to
 * `100.0000`; anything above is refused here, by name, before any arithmetic.
 * The tax context is always {@see TaxContext::Purchase}, so it is not a field.
 */
final readonly class PurchaseLineQuote
{
    public string $quantity;

    public string $unitCost;

    public string $discountPercent;

    public string $taxGroupId;

    public string $baseQuantity;

    public string $documentDate;

    /**
     * @throws PricingRefused invalid_amount
     */
    public function __construct(
        string $quantity,
        string $unitCost,
        string $discountPercent,
        string $taxGroupId,
        string $baseQuantity,
        string $documentDate,
    ) {
        $this->quantity = Shape::money($quantity, 'quantity');
        $this->unitCost = Shape::money($unitCost, 'unitCost');
        $this->discountPercent = Shape::rate($discountPercent, 'discountPercent');
        $this->taxGroupId = Shape::ulid($taxGroupId, 'taxGroupId');
        $this->baseQuantity = Shape::money($baseQuantity, 'baseQuantity');
        $this->documentDate = Shape::date($documentDate, 'documentDate');

        if (version_compare($this->discountPercent, '100.0000', '>')) {
            throw PricingRefused::invalidAmount("[discountPercent] must not exceed 100, got [{$this->discountPercent}].");
        }
    }
}
