<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ReopenFiscalYear;

/**
 * Reopen a closed fiscal year, with a reason (T-032 AC-42, AC-46).
 */
final class ReopenFiscalYear
{
    public function __construct(
        public readonly string $fiscalYearId,
        public readonly mixed $reason,
        public readonly ?string $actorId = null,
    ) {}
}
