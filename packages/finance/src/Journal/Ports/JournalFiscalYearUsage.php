<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal\Ports;

use Flexipos\Contracts\Finance\FiscalYearUsage;
use Flexipos\Finance\Support\TenantConnection;

/**
 * The journal's answer to "does this fiscal year hold entries?" (T-033
 * Ruling 11, AC-60, AC-62, AC-63).
 *
 * True when any entry, of **any** status, has an `entry_date` inside the dates
 * passed, both ends inclusive. The year id is ignored: the dates are the whole
 * question, which is why the contract passes them (the journal never reads
 * Finance's calendar tables). Shared lock, `entry_date` index.
 */
final class JournalFiscalYearUsage implements FiscalYearUsage
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function hasEntries(string $fiscalYearId, string $startDate, string $endDate): bool
    {
        return $this->tenant->get()
            ->table('journal_entries')
            ->whereBetween('entry_date', [$startDate, $endDate])
            ->sharedLock()
            ->exists();
    }
}
