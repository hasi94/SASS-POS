<?php

declare(strict_types=1);

namespace Flexipos\Sales\Sales;

/**
 * `sales.type` (`docs/database.md` section 6.7). `exchange` is a column value
 * only: nothing this task ships creates one (D-10).
 */
enum SaleType: string
{
    case Sale = 'sale';
    case Refund = 'refund';
    case Exchange = 'exchange';
}
