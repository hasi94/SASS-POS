<?php

declare(strict_types=1);

namespace Flexipos\Customers\Customers;

/**
 * `customers.status` (T-028 plan Ruling 7, AC-9).
 */
enum CustomerStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
