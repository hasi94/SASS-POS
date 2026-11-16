<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\DeleteFiscalYear;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Finance\FiscalYearUsage;
use Flexipos\Contracts\Finance\PeriodStatus;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;

/**
 * Delete a fiscal year (T-032 plan Ruling 6, step 13, AC-37).
 *
 * Allowed only for the **latest** year, only when **every period is open**,
 * and only when {@see FiscalYearUsage} reports no journal entries in the
 * year. The target is locked first and the latest year second, in the
 * global order (ascending `start_date`, years before periods), so two
 * deletes and a create queue rather than deadlock. The periods are removed
 * explicitly before the year; the foreign key's cascade is the backstop.
 */
final class DeleteFiscalYearHandler
{
    public const ACTION = 'fiscal_year_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Authorize $authorize,
        private readonly PeriodRows $rows,
        private readonly FiscalYearUsage $usage,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(DeleteFiscalYear $command): void
    {
        $this->authorize->require($command->actorId, FinancePermissions::PERIODS_MANAGE);

        $id = Input::id($command->fiscalYearId, 'fiscal_year_id');

        $this->tenant->get()->transaction(function () use ($command, $id): void {
            $year = $this->rows->yearForUpdate($id);

            if ($year === null) {
                throw FinanceRefused::because(
                    FinanceRefusal::FiscalYearNotFound,
                    "The fiscal year [{$id}] does not exist.",
                    'fiscal_year_id',
                    ['fiscal_year' => $id],
                );
            }

            $latest = $this->rows->latestYearForUpdate();

            if ($latest === null || (string) $latest->id !== $id) {
                throw FinanceRefused::because(
                    FinanceRefusal::NotLatestYear,
                    'Only the latest fiscal year can be deleted.',
                    'fiscal_year_id',
                    ['latest' => $latest === null ? null : (string) $latest->id],
                );
            }

            $notOpen = [];

            foreach ($this->rows->periodsOfYearForUpdate($id) as $period) {
                if ((string) $period->status !== PeriodStatus::Open->value) {
                    $notOpen[(string) $period->id] = (string) $period->status;
                }
            }

            if ($notOpen !== []) {
                throw FinanceRefused::because(
                    FinanceRefusal::FiscalYearInUse,
                    'The fiscal year has periods that are not open.',
                    'fiscal_year_id',
                    ['periods' => $notOpen],
                );
            }

            if ($this->usage->hasEntries($id, (string) $year->start_date, (string) $year->end_date)) {
                throw FinanceRefused::because(
                    FinanceRefusal::FiscalYearInUse,
                    'The fiscal year has journal entries.',
                    'fiscal_year_id',
                    ['entries' => true],
                );
            }

            $this->rows->deletePeriodsOfYear($id);
            $this->rows->deleteYear($id);

            $this->audit->record(
                FinanceAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'fiscal_year',
                $id,
                (array) $year,
                null,
            );
        }, 2);
    }
}
