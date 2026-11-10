<?php

declare(strict_types=1);

namespace Flexipos\Customers\Ledger;

/**
 * What `CreditLedgerCalculator::apply()` returns on success (T-028 plan
 * Ruling 4): the one new fact a writer needs - the resulting balance,
 * already canonical.
 */
final readonly class CreditOutcome
{
    public function __construct(
        public string $balanceAfter,
    ) {}
}
