<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ReopenFiscalYear;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Finance\FiscalYearStatus;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Periods\PeriodTransitions;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use LogicException;

/**
 * Reopen a closed fiscal year (T-032 plan Ruling 6, step 14, AC-42, AC-46).
 *
 * Needs `finance.periods.reopen` (separate from close) and a reason of 1 to
 * 500 characters. The year and **every later year** are locked ascending,
 * and every later year must be `open` (`later_year_not_open`): a closed year
 * is never left behind an open one. The reason is recorded in the audit row.
 */
final class ReopenFiscalYearHandler
{
    public const ACTION = 'fiscal_year_reopened';

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
    public function handle(ReopenFiscalYear $command): void
    {
        $this->authorize->require($command->actorId, FinancePermissions::PERIODS_REOPEN);

        $actorId = $command->actorId ?? throw new LogicException('Authorization passed for a null actor.');
        $id = Input::id($command->fiscalYearId, 'fiscal_year_id');
        $reason = PeriodTransitions::reason($command->reason, true);

        $this->tenant->get()->transaction(function () use ($id, $actorId, $reason): void {
            $coordinates = $this->rows->yearByIdUnlocked($id);

            if ($coordinates === null) {
                throw $this->notFound($id);
            }

            $chain = $this->rows->yearsFromForUpdate((string) $coordinates->start_date);
            $before = null;
            $later = [];

            foreach ($chain as $year) {
                if ((string) $year->id === $id) {
                    $before = $year;
                } else {
                    $later[] = $year;
                }
            }

            if ($before === null) {
                throw $this->notFound($id);
            }

            if ((string) $before->status !== FiscalYearStatus::Closed->value) {
                throw FinanceRefused::because(
                    FinanceRefusal::InvalidTransition,
                    'Only a closed fiscal year can be reopened.',
                    'fiscal_year_id',
                    ['status' => (string) $before->status],
                );
            }

            $notOpen = [];

            foreach ($later as $year) {
                if ((string) $year->status !== FiscalYearStatus::Open->value) {
                    $notOpen[(string) $year->id] = (string) $year->status;
                }
            }

            if ($notOpen !== []) {
                throw FinanceRefused::because(
                    FinanceRefusal::LaterYearNotOpen,
                    'A later fiscal year is not open; reopen it first.',
                    'fiscal_year_id',
                    ['years' => $notOpen],
                );
            }

            $this->rows->updateYear($id, [
                'status' => FiscalYearStatus::Open->value,
                'closed_at' => null,
                'closed_by' => null,
                'updated_at' => $this->clock->timestamp(),
            ]);

            $after = $this->rows->yearForUpdate($id);

            $this->audit->record(
                FinanceAuditActions::MODULE,
                $actorId,
                self::ACTION,
                'fiscal_year',
                $id,
                (array) $before,
                $after === null ? null : [...(array) $after, 'reason' => $reason],
            );
        }, 2);
    }

    private function notFound(string $id): FinanceRefused
    {
        return FinanceRefused::because(
            FinanceRefusal::FiscalYearNotFound,
            "The fiscal year [{$id}] does not exist.",
            'fiscal_year_id',
            ['fiscal_year' => $id],
        );
    }
}
