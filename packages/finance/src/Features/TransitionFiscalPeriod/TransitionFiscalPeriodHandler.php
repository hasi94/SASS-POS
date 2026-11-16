<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\TransitionFiscalPeriod;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Finance\FiscalYearStatus;
use Flexipos\Contracts\Finance\PeriodBlocker;
use Flexipos\Contracts\Finance\PeriodCloseBlockers;
use Flexipos\Contracts\Finance\PeriodStatus;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\PeriodRef;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Periods\PeriodTransitions;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Fields;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use LogicException;

/**
 * Move a period one step (T-032 plan Rulings 6, 7 and 9, step 14, AC-38 to
 * AC-44, AC-46).
 *
 * **Which permission the move needs is decided by the move**, so the pure
 * `transition()` check on the requested move comes first: an impossible move
 * is refused `invalid_transition` before authorization can tell the caller
 * anything. Then: `finance.periods.close` for a move up the close order,
 * `finance.periods.reopen` for a move down it (AC-64). Only then is the
 * reason parsed (a reopen needs one, AC-42) and a transaction opened.
 *
 * **Inside the transaction** the sequence is fixed (Ruling 7): the period's
 * immutable coordinates are read unlocked; its **year is locked** and must
 * not be closed (AC-46); the periods from the previous one's `start_date` to
 * the next one's are locked in ascending order; and every rule is evaluated
 * on those locked rows: `expected_status`, `transition`, order, then the
 * close blockers (a close or soft-close only, a reopen is never asked,
 * AC-41). `closed_at` and `closed_by` come from the server clock and the
 * actor (AC-43); no request can name them, because any key beyond the three
 * refuses the whole request.
 */
final class TransitionFiscalPeriodHandler
{
    public const ACTION_SOFT_CLOSED = 'fiscal_period_soft_closed';

    public const ACTION_CLOSED = 'fiscal_period_closed';

    public const ACTION_REOPENED = 'fiscal_period_reopened';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Authorize $authorize,
        private readonly PeriodRows $rows,
        private readonly PeriodCloseBlockers $blockers,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(TransitionFiscalPeriod $command): void
    {
        $id = Input::id($command->periodId, 'period_id');

        Fields::only($command->fields, ['status', 'expected_status', 'reason']);

        $to = Input::enum($command->fields['status'] ?? null, PeriodStatus::class, 'status');
        $expected = Input::enum($command->fields['expected_status'] ?? null, PeriodStatus::class, 'expected_status');

        /** @var PeriodStatus $to */
        /** @var PeriodStatus $expected */
        PeriodTransitions::transition($expected, $to);

        $reopen = PeriodTransitions::isReopen($expected, $to);

        $this->authorize->require(
            $command->actorId,
            $reopen ? FinancePermissions::PERIODS_REOPEN : FinancePermissions::PERIODS_CLOSE,
        );

        $actorId = $command->actorId ?? throw new LogicException('Authorization passed for a null actor.');
        $reason = PeriodTransitions::reason($command->fields['reason'] ?? null, $reopen);

        $this->tenant->get()->transaction(function () use ($id, $to, $expected, $reopen, $actorId, $reason): void {
            $coordinates = $this->rows->periodByIdUnlocked($id);

            if ($coordinates === null) {
                throw $this->periodNotFound($id);
            }

            $year = $this->rows->yearForUpdate((string) $coordinates->fiscal_year_id);

            if ($year === null) {
                throw $this->periodNotFound($id);
            }

            if ((string) $year->status !== FiscalYearStatus::Open->value) {
                throw FinanceRefused::because(
                    FinanceRefusal::FiscalYearClosed,
                    'The fiscal year is closed; reopen it before moving one of its periods.',
                    'period_id',
                    ['fiscal_year' => (string) $year->id],
                );
            }

            $start = (string) $coordinates->start_date;
            $locked = $this->rows->periodsBetweenForUpdate(
                $this->rows->previousPeriodStartUnlocked($start) ?? $start,
                $this->rows->nextPeriodStartUnlocked($start) ?? $start,
            );

            $at = null;

            foreach ($locked as $index => $row) {
                if ((string) $row->id === $id) {
                    $at = $index;
                }
            }

            if ($at === null) {
                throw $this->periodNotFound($id);
            }

            $before = $locked[$at];
            $stored = PeriodStatus::from((string) $before->status);

            PeriodTransitions::checkExpected($stored, $expected);
            PeriodTransitions::transition($stored, $to);
            PeriodTransitions::checkOrder(
                $this->ref($locked[$at - 1] ?? null),
                $to,
                $this->ref($locked[$at + 1] ?? null),
            );

            if (! $reopen) {
                $this->assertNotBlocked($id, $start, (string) $before->end_date);
            }

            $this->rows->updatePeriod($id, [
                'status' => $to->value,
                ...PeriodTransitions::closure($to, $this->clock->timestamp(), $actorId),
            ]);

            $after = $this->rows->periodForUpdate($id);

            $this->audit->record(
                FinanceAuditActions::MODULE,
                $actorId,
                $this->actionFor($stored, $to),
                'fiscal_period',
                $id,
                (array) $before,
                $after === null ? null : [...(array) $after, 'reason' => $reason],
            );
        }, 2);
    }

    private function actionFor(PeriodStatus $from, PeriodStatus $to): string
    {
        if (PeriodTransitions::isReopen($from, $to)) {
            return self::ACTION_REOPENED;
        }

        return $to === PeriodStatus::Closed ? self::ACTION_CLOSED : self::ACTION_SOFT_CLOSED;
    }

    private function ref(?object $row): ?PeriodRef
    {
        return $row === null
            ? null
            : new PeriodRef((string) $row->id, (string) $row->name, PeriodStatus::from((string) $row->status));
    }

    private function assertNotBlocked(string $id, string $start, string $end): void
    {
        $found = $this->blockers->blockersFor($id, $start, $end);

        if ($found === []) {
            return;
        }

        $kinds = [];

        foreach ($found as $blocker) {
            /** @var PeriodBlocker $blocker */
            $kinds[$blocker->kind] = ($kinds[$blocker->kind] ?? 0) + $blocker->count;
        }

        throw FinanceRefused::because(
            FinanceRefusal::CloseBlocked,
            'The period cannot be closed while something still needs it: '.implode(', ', array_keys($kinds)).'.',
            'status',
            ['blockers' => $kinds],
        );
    }

    private function periodNotFound(string $id): FinanceRefused
    {
        return FinanceRefused::because(
            FinanceRefusal::PeriodNotFound,
            "The fiscal period [{$id}] does not exist.",
            'period_id',
            ['period' => $id],
        );
    }
}
