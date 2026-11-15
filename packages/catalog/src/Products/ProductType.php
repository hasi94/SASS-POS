<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Products;

/**
 * `products.type` (T-027 AC-18, AC-19; `docs/database.md` section 6.3,
 * D-7). A variant is a `products` row with `type = Variant` and `parent_id`
 * pointing at a `VariantParent` row - there is no second table.
 *
 * **Plan Ruling 14.** This is the safe spelling of the retired module word
 * as a case value: a backed enum, referenced through `ProductType::Service`
 * and never through its bare string in a dataset key or array-literal tail
 * position, which is what trips `QaVocabularyRenameSweepTest`.
 */
enum ProductType: string
{
    case Simple = 'simple';
    case VariantParent = 'variant_parent';
    case Variant = 'variant';
    case Service = 'service';
    case Bundle = 'bundle';
    case Composite = 'composite';
}
