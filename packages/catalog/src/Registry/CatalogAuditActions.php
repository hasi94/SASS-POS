<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Registry;

use Flexipos\Catalog\Features\AddProductBarcode\AddProductBarcodeHandler;
use Flexipos\Catalog\Features\AddProductComponent\AddProductComponentHandler;
use Flexipos\Catalog\Features\AttachModifierGroup\AttachModifierGroupHandler;
use Flexipos\Catalog\Features\CreateBrand\CreateBrandHandler;
use Flexipos\Catalog\Features\CreateCategory\CreateCategoryHandler;
use Flexipos\Catalog\Features\CreateModifier\CreateModifierHandler;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroupHandler;
use Flexipos\Catalog\Features\CreateProduct\CreateProductHandler;
use Flexipos\Catalog\Features\CreateProductBranch\CreateProductBranchHandler;
use Flexipos\Catalog\Features\CreateProductLocation\CreateProductLocationHandler;
use Flexipos\Catalog\Features\CreateUnit\CreateUnitHandler;
use Flexipos\Catalog\Features\CreateUnitConversion\CreateUnitConversionHandler;
use Flexipos\Catalog\Features\DeleteBrand\DeleteBrandHandler;
use Flexipos\Catalog\Features\DeleteCategory\DeleteCategoryHandler;
use Flexipos\Catalog\Features\DeleteModifierGroup\DeleteModifierGroupHandler;
use Flexipos\Catalog\Features\DeleteProduct\DeleteProductHandler;
use Flexipos\Catalog\Features\DetachModifierGroup\DetachModifierGroupHandler;
use Flexipos\Catalog\Features\RemoveModifier\RemoveModifierHandler;
use Flexipos\Catalog\Features\RemoveProductBarcode\RemoveProductBarcodeHandler;
use Flexipos\Catalog\Features\RemoveProductBranch\RemoveProductBranchHandler;
use Flexipos\Catalog\Features\RemoveProductComponent\RemoveProductComponentHandler;
use Flexipos\Catalog\Features\RemoveProductLocation\RemoveProductLocationHandler;
use Flexipos\Catalog\Features\RemoveUnitConversion\RemoveUnitConversionHandler;
use Flexipos\Catalog\Features\UpdateBrand\UpdateBrandHandler;
use Flexipos\Catalog\Features\UpdateCategory\UpdateCategoryHandler;
use Flexipos\Catalog\Features\UpdateProduct\UpdateProductHandler;
use Flexipos\Catalog\Features\UpdateProductBarcode\UpdateProductBarcodeHandler;
use Flexipos\Catalog\Features\UpdateUnit\UpdateUnitHandler;

/**
 * The audit actions this package's handlers write through
 * `AuditTrail::record()` (T-027 plan Ruling 11, T-017 Ruling 3).
 *
 * **Built from each handler's own `ACTION` constant, so no literal is
 * copied** (the organization precedent): this list cannot drift from what
 * the handlers actually pass.
 */
final class CatalogAuditActions
{
    /**
     * The module every action below belongs to.
     */
    public const MODULE = 'catalog';

    /**
     * @var list<string>
     */
    public const ALL = [
        CreateUnitHandler::ACTION,
        UpdateUnitHandler::ACTION,
        CreateCategoryHandler::ACTION,
        UpdateCategoryHandler::ACTION,
        DeleteCategoryHandler::ACTION,
        CreateBrandHandler::ACTION,
        UpdateBrandHandler::ACTION,
        DeleteBrandHandler::ACTION,
        CreateProductHandler::ACTION,
        UpdateProductHandler::ACTION,
        DeleteProductHandler::ACTION,
        CreateUnitConversionHandler::ACTION,
        RemoveUnitConversionHandler::ACTION,
        AddProductBarcodeHandler::ACTION,
        UpdateProductBarcodeHandler::ACTION,
        RemoveProductBarcodeHandler::ACTION,
        CreateProductLocationHandler::ACTION,
        RemoveProductLocationHandler::ACTION,
        CreateProductBranchHandler::ACTION,
        RemoveProductBranchHandler::ACTION,
        AddProductComponentHandler::ACTION,
        RemoveProductComponentHandler::ACTION,
        CreateModifierGroupHandler::ACTION,
        DeleteModifierGroupHandler::ACTION,
        CreateModifierHandler::ACTION,
        RemoveModifierHandler::ACTION,
        AttachModifierGroupHandler::ACTION,
        DetachModifierGroupHandler::ACTION,
    ];
}
