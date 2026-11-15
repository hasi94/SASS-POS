<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * Which kind of document a tax is being computed for (AC-11.1). A tax whose
 * `applies_to` excludes the context is skipped, never an error.
 */
enum TaxContext: string
{
    case Sale = 'sale';

    case Purchase = 'purchase';
}
