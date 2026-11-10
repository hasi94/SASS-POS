<?php

declare(strict_types=1);

namespace Flexipos\Customers\Loyalty;

/**
 * What `LoyaltyCalculator::apply()` returns on success (T-028 plan Ruling 5):
 * the resulting points balance, already canonical.
 */
final readonly class LoyaltyOutcome
{
    public function __construct(
        public string $balanceAfter,
    ) {}
}
