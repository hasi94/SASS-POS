<?php

declare(strict_types=1);

namespace Flexipos\Finance\Periods;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Finance\PeriodClearance;
use Flexipos\Contracts\Finance\PeriodGate;
use Flexipos\Contracts\Finance\PeriodGateRefused;
use Flexipos\Contracts\Finance\PeriodStatus;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use LogicException;

/**
 * The published period gate, answered from the tenant database (T-032 plan
 * Rulings 2 and 6, step 15, AC-47 to AC-51).
 *
 * **Not a slice and asks no permission to be called** (Ruling 9): it is
 * called by trusted module code (the posting service) inside its own
 * transaction. It decides one thing, whether this date may be posted into by
 * this actor, and **fails closed**:
 *
 * - a date that is not a real strict `Y-m-d` is `invalid_date`, never
 *   "allowed" and never "today" (AC-48);
 * - a date no period covers, including every date in a tenant with no
 *   fiscal year, is `no_fiscal_period` (AC-48);
 * - a `closed` period refuses **every** actor, finance included (AC-47);
 * - a `soft_closed` period refuses everyone except an actor holding
 *   `finance.periods.post_adjustment`, and a null actor never holds it;
 * - an `open` period is allowed to any actor: whether the actor may post at
 *   all is the posting service's question, not the gate's (D-28).
 *
 * **Inside a transaction, with a shared lock** (AC-50): the one read takes a
 * shared lock on the period row, and every status transition takes an
 * exclusive one, so a close waits for an in-flight posting to commit and a
 * posting started after the close commits sees the new status. Called
 * outside a transaction it throws, because a lock that is released before
 * the caller writes protects nothing.
 *
 * It returns only the period id and status (AC-51), never a row.
 */
final class DatabasePeriodGate implements PeriodGate
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly PeriodRows $rows,
        private readonly PermissionResolver $resolver,
    ) {}

    public function clear(mixed $date, ?string $actorId): PeriodClearance
    {
        if ($this->tenant->get()->transactionLevel() < 1) {
            throw new LogicException('PeriodGate::clear() must be called inside the caller\'s transaction (AC-50).');
        }

        try {
            $day = Input::date($date, 'date');
        } catch (FinanceRefused $refused) {
            throw PeriodGateRefused::invalidDate($refused->getMessage());
        }

        $row = $this->rows->periodCoveringSharedLock($day);

        if ($row === null) {
            throw PeriodGateRefused::noFiscalPeriod("No fiscal period covers {$day}.");
        }

        $status = PeriodStatus::from((string) $row->status);

        if ($status === PeriodStatus::Closed) {
            throw PeriodGateRefused::periodClosed("The period covering {$day} is closed.");
        }

        if ($status === PeriodStatus::SoftClosed && ! $this->mayAdjust($actorId)) {
            throw PeriodGateRefused::periodSoftClosed("The period covering {$day} is soft-closed.");
        }

        return new PeriodClearance((string) $row->id, $status);
    }

    private function mayAdjust(?string $actorId): bool
    {
        return $actorId !== null
            && $this->resolver->decide($actorId, FinancePermissions::PERIODS_POST_ADJUSTMENT, null)->allowed;
    }
}
