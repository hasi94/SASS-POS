<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

/**
 * `taxes.type` (AC-5).
 */
enum TaxType: string
{
    /** `rate` is a percentage of a base. */
    case Percentage = 'percentage';

    /** `rate` is an amount per stock unit, charged on the base quantity (AC-11.4). */
    case Fixed = 'fixed';
}
