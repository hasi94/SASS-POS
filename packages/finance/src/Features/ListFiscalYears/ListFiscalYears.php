<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ListFiscalYears;

/**
 * List fiscal years, ascending by `start_date` (T-032 AC-62, AC-64).
 */
final class ListFiscalYears
{
    /**
     * @param  mixed  $pageSize  an integer 1 to the configured maximum, or null for the default
     * @param  mixed  $afterStart  the `nextAfterCode` of the previous page (a `Y-m-d` start date), or null
     */
    public function __construct(
        public readonly mixed $pageSize = null,
        public readonly mixed $afterStart = null,
        public readonly ?string $actorId = null,
    ) {}
}
