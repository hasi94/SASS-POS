<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Products;

/**
 * `products.price_mode` (T-027 AC-25; `docs/database.md` section 6.3).
 * Carries no coupling with `is_weighable` or `plu_code` (D-8).
 */
enum PriceMode: string
{
    case PerUnit = 'per_unit';
    case PerWeight = 'per_weight';
    case OpenPrice = 'open_price';
}
