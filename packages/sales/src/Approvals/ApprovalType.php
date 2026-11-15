<?php

declare(strict_types=1);

namespace Flexipos\Sales\Approvals;

/**
 * The `approval_requests.type` values this task builds a flow for (AC-23, D-13).
 *
 * `credit_limit`, `negative_stock` and `custom` exist as column values for
 * forward compatibility only and are deliberately not cases here, so no slice
 * can request or consume one.
 */
enum ApprovalType: string
{
    case Discount = 'discount';
    case Void = 'void';
    case Refund = 'refund';
    case PriceOverride = 'price_override';
}
