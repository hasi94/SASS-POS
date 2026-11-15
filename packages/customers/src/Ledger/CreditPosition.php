<?php

declare(strict_types=1);

namespace Flexipos\Customers\Ledger;

/**
 * The locked `customers` row's own values, exactly as `CreditLedgerCalculator`
 * needs them (T-028 plan Ruling 4).
 *
 * Plain data, no validation: the writer reads these straight off the row it
 * just locked with `FOR UPDATE` (Ruling 6), so there is nothing here to
 * check that the schema itself does not already guarantee.
 */
final readonly class CreditPosition
{
    public function __construct(
        public string $balance,
        public bool $creditAllowed,
        public string $creditLimit,
        public bool $active,
    ) {}
}
