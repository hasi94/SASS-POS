<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\FindPeriodByDate;

use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;

/**
 * Look a period up by date (T-032 plan Ruling 10, AC-62, AC-64).
 *
 * The same single indexed read the period gate makes, but **plain**: no lock,
 * and no question about who may post (that is the gate's, AC-47). A date no
 * period covers is `no_fiscal_period`, so a tenant with no year never
 * defaults to anything.
 */
final class FindPeriodByDateHandler
{
    public function __construct(
        private readonly Authorize $authorize,
        private readonly PeriodRows $rows,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws FinanceRefused
     */
    public function handle(FindPeriodByDate $query): array
    {
        $this->authorize->require($query->actorId, FinancePermissions::PERIODS_VIEW);

        $date = Input::date($query->date, 'date');
        $row = $this->rows->periodCoveringUnlocked($date);

        if ($row === null) {
            throw FinanceRefused::because(
                FinanceRefusal::NoFiscalPeriod,
                "No fiscal period covers {$date}.",
                'date',
                ['date' => $date],
            );
        }

        return (array) $row;
    }
}
