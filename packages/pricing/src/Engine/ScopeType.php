<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

/**
 * `price_list_assignments.scope_type` (AC-14). `scope_id` is polymorphic and
 * names a row in the table this type says.
 */
enum ScopeType: string
{
    case Branch = 'branch';

    case CustomerGroup = 'customer_group';

    case Customer = 'customer';
}
