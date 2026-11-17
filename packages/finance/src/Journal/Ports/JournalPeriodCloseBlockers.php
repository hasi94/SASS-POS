<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal\Ports;

use Flexipos\Contracts\Finance\JournalEntryStatus;
use Flexipos\Contracts\Finance\PeriodBlocker;
use Flexipos\Contracts\Finance\PeriodCloseBlockers;
use Flexipos\Finance\Support\TenantConnection;

/**
 * What stops a period from closing, from the journal (T-033 Ruling 11, AC-61,
 * AC-62, AC-63, D-5, D-12).
 *
 * The one blocker is `draft_journal_entries`: entries in status `draft` dated
 * inside the range, both ends inclusive. A posted or reversed entry never
 * blocks a close. The period id is ignored; the dates are the question.
 * Shared lock, `entry_date` index.
 */
final class JournalPeriodCloseBlockers implements PeriodCloseBlockers
{
    public const DRAFT_KIND = 'draft_journal_entries';

    public function __construct(private readonly TenantConnection $tenant) {}

    public function blockersFor(string $periodId, string $startDate, string $endDate): array
    {
        $drafts = $this->tenant->get()
            ->table('journal_entries')
            ->where('status', JournalEntryStatus::Draft->value)
            ->whereBetween('entry_date', [$startDate, $endDate])
            ->sharedLock()
            ->count();

        return $drafts > 0 ? [new PeriodBlocker(self::DRAFT_KIND, $drafts)] : [];
    }
}
