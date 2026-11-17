<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\CloseFiscalYear;

/**
 * Close a fiscal year whose periods are all closed (T-032 AC-45).
 */
final class CloseFiscalYear
{
    public function __construct(
        public readonly string $fiscalYearId,
        public readonly ?string $actorId = null,
    ) {}
}
