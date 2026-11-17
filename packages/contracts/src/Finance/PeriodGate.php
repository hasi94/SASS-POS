<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

use LogicException;

/**
 * The one question the posting service asks before it writes: may this date
 * be posted into, by this actor? (T-032 AC-47 to AC-51.)
 *
 * It answers only the period question, never who may post at all (D-28).
 * It fails closed: a date that cannot be read, a date no period covers and a
 * tenant with no fiscal year are all refusals, never "allowed".
 */
interface PeriodGate
{
    /**
     * Resolve `$date` to the one period covering it, or refuse.
     *
     * **Must run inside the caller's open transaction** and takes a shared
     * lock on the period row, so a close that waits for an in-flight posting
     * and a posting started after the close both see the right status
     * (AC-50).
     *
     * **Lock order**: call it once per transaction, or for several dates in
     * ascending date order, so two postings never take period locks in opposite
     * orders. Lock behaviour is not verified against a real engine.
     *
     * @param  mixed  $date  A strict `Y-m-d` string; anything else is refused `invalid_date`
     * @param  string|null  $actorId  The posting employee; null can never hold the adjustment permission
     *
     * @throws PeriodGateRefused
     * @throws LogicException when no transaction is open
     */
    public function clear(mixed $date, ?string $actorId): PeriodClearance;
}
