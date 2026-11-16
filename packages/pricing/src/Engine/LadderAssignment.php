<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use InvalidArgumentException;

/**
 * One `price_list_assignments` row (AC-14): the list applies to the branch,
 * customer group or customer named by `scopeId`. `priority` orders several
 * lists assigned to the same scope (D-16).
 */
final readonly class LadderAssignment
{
    public function __construct(
        public ScopeType $scopeType,
        public string $scopeId,
        public int $priority = 0,
    ) {
        if ($scopeId === '') {
            throw new InvalidArgumentException('[scopeId] must not be blank.');
        }
    }
}
