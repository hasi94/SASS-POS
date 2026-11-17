<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\CloseFiscalYear;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Finance\FiscalYearStatus;
use Flexipos\Contracts\Finance\PeriodStatus;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use LogicException;

/**
 * Close a fiscal year (T-032 plan Ruling 6, step 14, AC-45).
 *
 * Needs `finance.periods.close`. The year is locked, then its periods in
 * ascending order; the year must be `open` and **every** period `closed`.
 * On success the year becomes `closed` with `closed_at` and `closed_by`, and
 * `retained_earnings_entry_id` stays NULL: the retained-earnings entry is not
 * generated here and the `closing` state is never entered (D-5).
 */
final class CloseFiscalYearHandler
{
    public const ACTION = 'fiscal_year_closed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Authorize $authorize,
        private readonly PeriodRows $rows,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(CloseFiscalYear $command): void
    {
        $this->authorize->require($command->actorId, FinancePermissions::PERIODS_CLOSE);

        $actorId = $command->actorId ?? throw new LogicException('Authorization passed for a null actor.');
        $id = Input::id($command->fiscalYearId, 'fiscal_year_id');

        $this->tenant->get()->transaction(function () use ($id, $actorId): void {
            $before = $this->rows->yearForUpdate($id);

            if ($before === null) {
                throw FinanceRefused::because(
                    FinanceRefusal::FiscalYearNotFound,
                    "The fiscal year [{$id}] does not exist.",
                    'fiscal_year_id',
                    ['fiscal_year' => $id],
                );
            }

            if ((string) $before->status !== FiscalYearStatus::Open->value) {
                throw FinanceRefused::because(
                    FinanceRefusal::FiscalYearNotOpen,
                    'The fiscal year is not open.',
                    'fiscal_year_id',
                    ['status' => (string) $before->status],
                );
            }

            $notClosed = [];

            foreach ($this->rows->periodsOfYearForUpdate($id) as $period) {
                if ((string) $period->status !== PeriodStatus::Closed->value) {
                    $notClosed[(string) $period->id] = (string) $period->status;
                }
            }

            if ($notClosed !== []) {
                throw FinanceRefused::because(
                    FinanceRefusal::PeriodsNotClosed,
                    'Every period of the year must be closed first; '.count($notClosed).' are not.',
                    'fiscal_year_id',
                    ['periods' => $notClosed],
                );
            }

            $now = $this->clock->timestamp();

            $this->rows->updateYear($id, [
                'status' => FiscalYearStatus::Closed->value,
                'closed_at' => $now,
                'closed_by' => $actorId,
                'updated_at' => $now,
            ]);

            $after = $this->rows->yearForUpdate($id);

            $this->audit->record(
                FinanceAuditActions::MODULE,
                $actorId,
                self::ACTION,
                'fiscal_year',
                $id,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        }, 2);
    }
}
