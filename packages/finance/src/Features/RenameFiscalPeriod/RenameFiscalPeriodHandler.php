<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\RenameFiscalPeriod;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Fields;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\Limits;
use Flexipos\Finance\Support\TenantConnection;

/**
 * Rename a fiscal period (T-032 plan Ruling 9, step 13, AC-34, AC-36).
 *
 * Only `name` may be sent; any other key refuses the whole request. A period
 * name is 1 to 50 characters and is **not** unique (no index and no rule
 * asks for it). `fiscal_periods` has no `updated_at` (F-18, D-28), so the
 * audit row is the only record that the rename happened (AC-65).
 */
final class RenameFiscalPeriodHandler
{
    public const ACTION = 'fiscal_period_renamed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Authorize $authorize,
        private readonly PeriodRows $rows,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(RenameFiscalPeriod $command): void
    {
        $this->authorize->require($command->actorId, FinancePermissions::PERIODS_MANAGE);

        $id = Input::id($command->periodId, 'period_id');

        Fields::only($command->fields, ['name']);

        $name = (string) Input::text($command->fields['name'] ?? null, Limits::PERIOD_NAME, true, 'name');

        $this->tenant->get()->transaction(function () use ($command, $id, $name): void {
            $before = $this->rows->periodForUpdate($id);

            if ($before === null) {
                throw FinanceRefused::because(
                    FinanceRefusal::PeriodNotFound,
                    "The fiscal period [{$id}] does not exist.",
                    'period_id',
                    ['period' => $id],
                );
            }

            $this->rows->updatePeriod($id, ['name' => $name]);

            $after = $this->rows->periodForUpdate($id);

            $this->audit->record(
                FinanceAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'fiscal_period',
                $id,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        }, 2);
    }
}
