<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

/**
 * `price_lists.type` (AC-12). The ladder selects by assignment, not by type
 * (D-16), with one exception: step 3 takes the promotion lists and steps 1, 2
 * and 4 take every list that is not a promotion.
 */
enum ListType: string
{
    case Standard = 'standard';

    case CustomerGroup = 'customer_group';

    case Promotion = 'promotion';

    case Branch = 'branch';
}
