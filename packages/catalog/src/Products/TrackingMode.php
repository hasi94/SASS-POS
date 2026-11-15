<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Products;

/**
 * `products.tracking_mode` (T-027 AC-26; `docs/database.md` section 6.3).
 * Carries no coupling with `track_stock`.
 */
enum TrackingMode: string
{
    case None = 'none';
    case Quantity = 'quantity';
    case Batch = 'batch';
    case Serial = 'serial';
}
