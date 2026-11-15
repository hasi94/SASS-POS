<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\RecordCreditLedgerEntry;

/**
 * Record one `customer_ledger` entry (T-028 AC-19 to AC-24; plan Ruling 6).
 */
final class RecordCreditLedgerEntry
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
