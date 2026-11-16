<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

use InvalidArgumentException;

/**
 * One reason a period may not be closed, as reported by a
 * {@see PeriodCloseBlockers} (T-032 AC-41): a kind, for example
 * `draft_journal_entries`, and how many.
 */
final readonly class PeriodBlocker
{
    public function __construct(
        public string $kind,
        public int $count,
    ) {
        if ($kind === '') {
            throw new InvalidArgumentException('[kind] must not be empty.');
        }

        if ($count < 0) {
            throw new InvalidArgumentException('[count] must not be negative.');
        }
    }
}
