<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tenders;

/**
 * What a tender comes to once the method's rules have been applied: the amount
 * that settles the sale, what the customer handed over (null when the method
 * gives no change), and the change paid back.
 */
final readonly class TenderOutcome
{
    public function __construct(
        public string $amount,
        public ?string $tendered,
        public string $changeGiven,
    ) {}
}
