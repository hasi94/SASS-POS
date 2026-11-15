<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\TaxContext;

/**
 * `taxes.applies_to` (AC-5): which documents a tax is charged on.
 */
enum TaxApplies: string
{
    case Sale = 'sale';

    case Purchase = 'purchase';

    case Both = 'both';

    public function covers(TaxContext $context): bool
    {
        return $this === self::Both || $this->value === $context->value;
    }
}
