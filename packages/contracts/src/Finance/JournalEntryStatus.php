<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The lifecycle of a journal entry (T-033 D-5).
 *
 * The service writes `posted` and, through `reverse`, `reversed`. `draft` is
 * documented and counted by {@see PeriodCloseBlockers}, but no operation of
 * this task creates one.
 */
enum JournalEntryStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';
}
