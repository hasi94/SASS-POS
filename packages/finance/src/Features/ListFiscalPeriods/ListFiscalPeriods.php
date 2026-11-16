<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ListFiscalPeriods;

/**
 * List the periods of one fiscal year, by sequence (T-032 AC-62, AC-64).
 */
final class ListFiscalPeriods
{
    public function __construct(
        public readonly string $fiscalYearId,
        public readonly ?string $actorId = null,
    ) {}
}
