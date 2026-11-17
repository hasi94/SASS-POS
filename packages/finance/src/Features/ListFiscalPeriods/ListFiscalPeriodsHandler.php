<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ListFiscalPeriods;

use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;

/**
 * List one year's periods, ordered by sequence (T-032 AC-62, AC-64). A year
 * has at most twelve, so there is no paging. An unknown year is refused.
 */
final class ListFiscalPeriodsHandler
{
    public function __construct(
        private readonly Authorize $authorize,
        private readonly PeriodRows $rows,
    ) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws FinanceRefused
     */
    public function handle(ListFiscalPeriods $query): array
    {
        $this->authorize->require($query->actorId, FinancePermissions::PERIODS_VIEW);

        $id = Input::id($query->fiscalYearId, 'fiscal_year_id');

        if ($this->rows->yearByIdUnlocked($id) === null) {
            throw FinanceRefused::because(
                FinanceRefusal::FiscalYearNotFound,
                "The fiscal year [{$id}] does not exist.",
                'fiscal_year_id',
                ['fiscal_year' => $id],
            );
        }

        return array_map(
            static fn (object $row): array => (array) $row,
            $this->rows->periodsOfYearUnlocked($id),
        );
    }
}
