<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Products;

use Flexipos\Catalog\Exceptions\CatalogRefused;

/**
 * The pure rules behind AC-19 and AC-20's type clause (T-027 plan Ruling 8),
 * run on every create and every update:
 *
 * - only a `variant` has a `parent_id`;
 * - that parent exists, is live and has type `variant_parent`;
 * - a `variant_parent` with live children may not change type;
 * - a variant whose parent changes is re-checked (the caller re-runs
 *   {@see self::checkParentage()} and {@see self::checkParent()} with the
 *   new parent, exactly as a create would).
 *
 * **Pure.** The handler does every database read; this class only judges
 * the values handed to it.
 */
final class ProductRules
{
    /**
     * @throws CatalogRefused validation
     */
    public static function checkParentage(ProductType $type, ?string $parentId): void
    {
        if ($type === ProductType::Variant) {
            if ($parentId === null) {
                throw CatalogRefused::validation('A variant must have a parent_id.');
            }

            return;
        }

        if ($parentId !== null) {
            throw CatalogRefused::validation('Only a variant may have a parent_id.');
        }
    }

    /**
     * @throws CatalogRefused notFound, validation
     */
    public static function checkParent(string $parentId, bool $parentExistsAndIsLive, ?ProductType $parentType): void
    {
        if (! $parentExistsAndIsLive) {
            throw CatalogRefused::notFound("The parent product [{$parentId}] does not exist or is soft-deleted.");
        }

        if ($parentType !== ProductType::VariantParent) {
            throw CatalogRefused::validation("The parent product [{$parentId}] must be a variant_parent.");
        }
    }

    /**
     * @throws CatalogRefused validation
     */
    public static function checkTypeChange(string $productId, ProductType $currentType, ProductType $newType, bool $hasLiveChildren): void
    {
        if ($currentType === ProductType::VariantParent && $newType !== $currentType && $hasLiveChildren) {
            throw CatalogRefused::validation("Product [{$productId}] has a live variant and may not change type.");
        }
    }
}
