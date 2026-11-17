<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

/**
 * `discounts.scope` (AC-19): a discount applies to one line, or to the bill.
 */
enum DiscountScope: string
{
    case Item = 'item';

    case Bill = 'bill';
}
