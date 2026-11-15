<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * What a caller asks `PricingEngine::computeTax()` to compute (plan Ruling 2):
 * the tax of an amount the caller already has, such as a purchase line.
 *
 * `amount` is the net for an exclusive group and the gross for an inclusive
 * one. `baseQuantity` is the stock-unit quantity a `fixed` tax is charged on.
 */
final readonly class TaxQuote
{
    public string $taxGroupId;

    public string $amount;

    public string $baseQuantity;

    public string $documentDate;

    public function __construct(
        string $taxGroupId,
        string $amount,
        string $baseQuantity,
        string $documentDate,
        public TaxContext $context,
    ) {
        $this->taxGroupId = Shape::ulid($taxGroupId, 'taxGroupId');
        $this->amount = Shape::money($amount, 'amount');
        $this->baseQuantity = Shape::money($baseQuantity, 'baseQuantity');
        $this->documentDate = Shape::date($documentDate, 'documentDate');
    }
}
