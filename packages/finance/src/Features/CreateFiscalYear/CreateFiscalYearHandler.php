<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\CreateFiscalYear;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Finance\FiscalYearStatus;
use Flexipos\Contracts\Finance\PeriodStatus;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\FiscalCalendar;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Fields;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\Limits;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a fiscal year (T-032 plan Ruling 6, step 13, AC-31 to AC-35).
 *
 * Order: authorize, parse (every rejection that needs no lock), then one
 * transaction: lock the latest year, check the new one starts the day after
 * it ends, insert the year and its twelve periods, then **read the whole
 * chain back under a lock and verify it is contiguous**. Two simultaneous
 * creates queue behind the latest-year lock, but with no year at all there
 * is nothing to lock, so the verification (and the unique indexes on
 * `fiscal_years.start_date` and `fiscal_periods.start_date`, which two
 * overlapping years always collide on) is what makes exactly one of them win
 * (AC-35). Any fault part-way through rolls the whole year back (AC-31).
 *
 * **A year name is at most 46 characters here, not the 50 the column
 * holds**: every period is named `<year name> P01`, and a period name is at
 * most 50 characters, so a longer year name could not name its own periods.
 * It is refused, never truncated (AC-4's rule for every string).
 */
final class CreateFiscalYearHandler
{
    public const ACTION = 'fiscal_year_created';

    /** The longest year name that can still name its periods (`<name> P12`). */
    public const NAME_MAX = Limits::PERIOD_NAME - 4;

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
    public function handle(CreateFiscalYear $command): CreateFiscalYearResult
    {
        $this->authorize->require($command->actorId, FinancePermissions::PERIODS_MANAGE);

        Fields::only($command->fields, ['name', 'start_date']);

        $name = (string) Input::text($command->fields['name'] ?? null, self::NAME_MAX, true, 'name');
        $start = Input::date($command->fields['start_date'] ?? null, 'start_date');
        $periods = FiscalCalendar::periodsFor($start);
        $end = FiscalCalendar::yearEnd($start);
        $id = strtoupper((string) Str::ulid());

        $this->tenant->get()->transaction(function () use ($command, $name, $start, $end, $periods, $id): void {
            $latest = $this->rows->latestYearForUpdate();

            FiscalCalendar::contiguous($latest === null ? null : (string) $latest->end_date, $start);

            if ($this->rows->yearByNameSharedLock($name) !== null) {
                throw $this->nameInUse();
            }

            $now = $this->clock->timestamp();

            try {
                $this->rows->insertYear([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'name' => $name,
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => FiscalYearStatus::Open->value,
                    'closed_at' => null,
                    'closed_by' => null,
                    'retained_earnings_entry_id' => null,
                ]);

                foreach ($periods as $period) {
                    $this->rows->insertPeriod([
                        'id' => strtoupper((string) Str::ulid()),
                        'fiscal_year_id' => $id,
                        'sequence' => $period['sequence'],
                        'name' => $name.' '.$period['name'],
                        'start_date' => $period['start'],
                        'end_date' => $period['end'],
                        'status' => PeriodStatus::Open->value,
                        'closed_at' => null,
                        'closed_by' => null,
                    ]);
                }
            } catch (UniqueConstraintViolationException) {
                throw $this->backstopRefusal($name, $start, $id);
            }

            $this->verifyChain();

            $after = $this->rows->yearForUpdate($id);

            $this->audit->record(
                FinanceAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'fiscal_year',
                $id,
                null,
                $after === null ? null : (array) $after,
            );
        }, 2);

        return new CreateFiscalYearResult($id);
    }

    /**
     * Every year, ascending and locked, must start the day after the one
     * before it ends (AC-35). The check the stand-in cannot race, and the one
     * a created year cannot skip.
     */
    private function verifyChain(): void
    {
        $previousEnd = null;

        foreach ($this->rows->yearChainForUpdate() as $year) {
            FiscalCalendar::contiguous($previousEnd, (string) $year->start_date);
            $previousEnd = (string) $year->end_date;
        }
    }

    /**
     * A unique index fired: the name index or one of the two start-date
     * indexes. Work out which by asking, under a lock.
     */
    private function backstopRefusal(string $name, string $start, string $ownId): FinanceRefused
    {
        if ($this->rows->yearByNameSharedLock($name, $ownId) !== null) {
            return $this->nameInUse();
        }

        return FinanceRefused::because(
            FinanceRefusal::FiscalYearNotContiguous,
            'A fiscal year or period already covers part of that span.',
            'start_date',
            ['start_date' => $start],
        );
    }

    private function nameInUse(): FinanceRefused
    {
        return FinanceRefused::because(
            FinanceRefusal::NameInUse,
            'Another fiscal year already has that name.',
            'name',
        );
    }
}
