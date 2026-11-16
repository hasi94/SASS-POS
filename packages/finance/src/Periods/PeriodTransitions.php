<?php

declare(strict_types=1);

namespace Flexipos\Finance\Periods;

use Flexipos\Contracts\Finance\PeriodStatus;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Support\Input;

/**
 * The period state machine, pure (T-032 plan Ruling 7; AC-38 to AC-43).
 *
 * `open -> soft_closed -> closed` and back one step at a time. Every method
 * takes the facts it decides on and either returns or throws; the handler
 * (chunk D) gathers them under row locks. The handler's fixed sequence is:
 * the year is not closed (AC-46), then {@see self::checkExpected()}, then
 * {@see self::transition()}, then {@see self::checkOrder()}, then the close
 * blockers (close and soft-close only).
 */
final class PeriodTransitions
{
    /** The longest reason, in characters (AC-42). */
    public const REASON_MAX = 500;

    /**
     * Only the four adjacent moves are allowed: open to soft_closed,
     * soft_closed to closed, closed to soft_closed, soft_closed to open.
     * Anything else, including a move to the same status, is refused and
     * writes nothing (AC-38).
     *
     * @throws FinanceRefused invalid_transition
     */
    public static function transition(PeriodStatus $from, PeriodStatus $to): void
    {
        if (abs($from->rank() - $to->rank()) !== 1) {
            throw FinanceRefused::because(
                FinanceRefusal::InvalidTransition,
                "A period cannot move from {$from->value} to {$to->value}; only the adjacent moves are allowed.",
                'status',
                ['from' => $from->value, 'to' => $to->value],
            );
        }
    }

    /**
     * Whether a move goes back down the close order (AC-42, AC-46): the moves
     * that need a reason and the reopen permission.
     */
    public static function isReopen(PeriodStatus $from, PeriodStatus $to): bool
    {
        return $to->rank() < $from->rank();
    }

    /**
     * Compare-and-set on the status the caller saw (AC-39). A replayed close
     * therefore never applies twice, and never succeeds silently against a
     * row that has moved on.
     *
     * @throws FinanceRefused status_changed, naming the stored status
     */
    public static function checkExpected(PeriodStatus $stored, PeriodStatus $expected): void
    {
        if ($stored !== $expected) {
            throw FinanceRefused::because(
                FinanceRefusal::StatusChanged,
                "The period is {$stored->value}, not {$expected->value}.",
                'expected_status',
                ['status' => $stored->value],
            );
        }
    }

    /**
     * No earlier period may sit at a lower rank than a later one (AC-40):
     * `previous >= new >= next` in rank. Looking at the two immediate
     * neighbours is enough, because the rule held before this move.
     *
     * @throws FinanceRefused period_order_violation, naming the offending neighbour
     */
    public static function checkOrder(?PeriodRef $previous, PeriodStatus $new, ?PeriodRef $next): void
    {
        if ($previous !== null && $previous->status->rank() < $new->rank()) {
            throw self::orderViolation($previous, "{$previous->name} must be closed before a later period.");
        }

        if ($next !== null && $next->status->rank() > $new->rank()) {
            throw self::orderViolation($next, "{$next->name} must be reopened before an earlier period.");
        }
    }

    /**
     * The reason for a move (AC-42). A reopen needs one: 1 to 500 characters,
     * not blank. A close may carry one or none.
     *
     * @throws FinanceRefused reason_required, validation
     */
    public static function reason(mixed $reason, bool $required): ?string
    {
        if (! $required) {
            return Input::text($reason, self::REASON_MAX, false, 'reason');
        }

        try {
            return Input::text($reason, self::REASON_MAX, true, 'reason');
        } catch (FinanceRefused) {
            throw FinanceRefused::because(
                FinanceRefusal::ReasonRequired,
                'Reopening needs a reason of 1 to '.self::REASON_MAX.' characters.',
                'reason',
            );
        }
    }

    /**
     * `closed_at` and `closed_by` after a move (AC-43): set to the moment and
     * the actor on a move into soft_closed or closed, cleared on a move to open.
     *
     * @return array{closed_at: string|null, closed_by: string|null}
     */
    public static function closure(PeriodStatus $new, string $now, string $actorId): array
    {
        return $new === PeriodStatus::Open
            ? ['closed_at' => null, 'closed_by' => null]
            : ['closed_at' => $now, 'closed_by' => $actorId];
    }

    private static function orderViolation(PeriodRef $offender, string $message): FinanceRefused
    {
        return FinanceRefused::because(
            FinanceRefusal::PeriodOrderViolation,
            $message,
            'status',
            ['period_id' => $offender->id, 'name' => $offender->name],
        );
    }
}
