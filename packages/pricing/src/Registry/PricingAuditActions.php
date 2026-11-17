<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Registry;

use Flexipos\Pricing\Features\AddPriceListItem\AddPriceListItemHandler;
use Flexipos\Pricing\Features\AddTaxGroupTax\AddTaxGroupTaxHandler;
use Flexipos\Pricing\Features\AssignPriceList\AssignPriceListHandler;
use Flexipos\Pricing\Features\CloseTaxRate\CloseTaxRateHandler;
use Flexipos\Pricing\Features\CreateDiscount\CreateDiscountHandler;
use Flexipos\Pricing\Features\CreatePriceList\CreatePriceListHandler;
use Flexipos\Pricing\Features\CreateTax\CreateTaxHandler;
use Flexipos\Pricing\Features\CreateTaxAuthority\CreateTaxAuthorityHandler;
use Flexipos\Pricing\Features\CreateTaxGroup\CreateTaxGroupHandler;
use Flexipos\Pricing\Features\CreateTaxRate\CreateTaxRateHandler;
use Flexipos\Pricing\Features\DeleteDiscount\DeleteDiscountHandler;
use Flexipos\Pricing\Features\DeletePriceList\DeletePriceListHandler;
use Flexipos\Pricing\Features\RemovePriceListItem\RemovePriceListItemHandler;
use Flexipos\Pricing\Features\RemoveTaxGroupTax\RemoveTaxGroupTaxHandler;
use Flexipos\Pricing\Features\UnassignPriceList\UnassignPriceListHandler;
use Flexipos\Pricing\Features\UpdateDiscount\UpdateDiscountHandler;
use Flexipos\Pricing\Features\UpdatePriceList\UpdatePriceListHandler;
use Flexipos\Pricing\Features\UpdatePriceListItem\UpdatePriceListItemHandler;
use Flexipos\Pricing\Features\UpdateTax\UpdateTaxHandler;
use Flexipos\Pricing\Features\UpdateTaxAuthority\UpdateTaxAuthorityHandler;
use Flexipos\Pricing\Features\UpdateTaxGroup\UpdateTaxGroupHandler;

/**
 * The audit actions this package's handlers write through
 * `AuditTrail::record()` (T-017 Ruling 3).
 *
 * **Built from each handler's own `ACTION` constant, so no literal is copied**
 * (the catalog precedent): this list cannot drift from what the handlers
 * actually pass.
 */
final class PricingAuditActions
{
    /**
     * The module every action below belongs to.
     */
    public const MODULE = 'pricing';

    /**
     * @var list<string>
     */
    public const ALL = [
        CreateTaxAuthorityHandler::ACTION,
        UpdateTaxAuthorityHandler::ACTION,
        CreateTaxHandler::ACTION,
        UpdateTaxHandler::ACTION,
        CreateTaxRateHandler::ACTION,
        CloseTaxRateHandler::ACTION,
        CreateTaxGroupHandler::ACTION,
        UpdateTaxGroupHandler::ACTION,
        AddTaxGroupTaxHandler::ACTION,
        RemoveTaxGroupTaxHandler::ACTION,
        CreatePriceListHandler::ACTION,
        UpdatePriceListHandler::ACTION,
        DeletePriceListHandler::ACTION,
        AddPriceListItemHandler::ACTION,
        UpdatePriceListItemHandler::ACTION,
        RemovePriceListItemHandler::ACTION,
        AssignPriceListHandler::ACTION,
        UnassignPriceListHandler::ACTION,
        CreateDiscountHandler::ACTION,
        UpdateDiscountHandler::ACTION,
        DeleteDiscountHandler::ACTION,
    ];
}
