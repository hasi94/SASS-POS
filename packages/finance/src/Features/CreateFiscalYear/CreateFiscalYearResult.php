<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\CreateFiscalYear;

/**
 * The id of the fiscal year a {@see CreateFiscalYearHandler} created.
 */
final readonly class CreateFiscalYearResult
{
    public function __construct(public string $fiscalYearId) {}
}
