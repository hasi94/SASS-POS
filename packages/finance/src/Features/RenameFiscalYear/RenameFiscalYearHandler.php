<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\RenameFiscalYear;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYearHandler;
use Flexipos\Finance\Periods\FiscalCalendar;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Fields;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Rename a fiscal year (T-032 plan Ruling 9, step 13, AC-34, AC-36).
 *
 * `name` is the only field a year can be given: every other column
 * (`start_date`, `end_date`, `status`, `closed_at`, `closed_by`,
 * `retained_earnings_entry_id`) refuses the **whole** request by name, because
 * a status moves only through the transitions (AC-36). The new name is
 * compared `lower(trim())` against every other year, and the unique index is
 * the backstop. The year's periods keep the names they were given.
 *
 * **Lock order (D-33)**: the whole year chain is read ascending under
 * `FOR UPDATE` first, and the year being renamed is found in it, so two
 * renames of different years queue in the same order and cannot cycle. The
 * name is capped at {@see CreateFiscalYearHandler::NAME_MAX} like a create
 * (D-31 a): the periods are named `<year> P01`.
 */
final class RenameFiscalYearHandler
{
    public const ACTION = 'fiscal_year_renamed';

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
    public function handle(RenameFiscalYear $command): void
    {
        $this->authorize->require($command->actorId, FinancePermissions::PERIODS_MANAGE);

        $id = Input::id($command->fiscalYearId, 'fiscal_year_id');

        Fields::only($command->fields, ['name']);

        $name = (string) Input::text($command->fields['name'] ?? null, CreateFiscalYearHandler::NAME_MAX, true, 'name');

        $this->tenant->get()->transaction(function () use ($command, $id, $name): void {
            $chain = $this->rows->yearChainForUpdate();
            $before = null;

            foreach ($chain as $year) {
                if ((string) $year->id === $id) {
                    $before = $year;
                }
            }

            if ($before === null) {
                throw FinanceRefused::because(
                    FinanceRefusal::FiscalYearNotFound,
                    "The fiscal year [{$id}] does not exist.",
                    'fiscal_year_id',
                    ['fiscal_year' => $id],
                );
            }

            if (FiscalCalendar::nameTaken($chain, $name, $id)) {
                throw $this->nameInUse();
            }

            try {
                $this->rows->updateYear($id, ['name' => $name, 'updated_at' => $this->clock->timestamp()]);
            } catch (UniqueConstraintViolationException) {
                throw $this->nameInUse();
            }

            $after = $this->rows->yearForUpdate($id);

            $this->audit->record(
                FinanceAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'fiscal_year',
                $id,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        }, 2);
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
