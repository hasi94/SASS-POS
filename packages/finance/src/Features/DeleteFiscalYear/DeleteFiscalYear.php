<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\DeleteFiscalYear;

/**
 * Delete a fiscal year and its periods (T-032 AC-37).
 */
final class DeleteFiscalYear
{
    public function __construct(
        public readonly string $fiscalYearId,
        public readonly ?string $actorId = null,
    ) {}
}
