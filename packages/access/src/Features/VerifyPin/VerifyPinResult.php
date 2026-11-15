<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\VerifyPin;

/**
 * The answer to one PIN verification: an employee, or the one refusal
 * (AC-49, AC-50).
 *
 * **There is exactly one refusal value, and the type is what guarantees
 * it.** AC-50 requires that an unknown code, a wrong PIN, an employee with
 * no PIN, a locked one and a non-acting one be indistinguishable to the
 * caller - a till told "wrong PIN" rather than "no such employee" is an
 * oracle that turns a 10^4 space into a 10^4-plus-codes space. So
 * {@see self::refused()} carries no cause at all: two refusals built for
 * different reasons are `==` equal, and there is no field a caller could
 * branch on even carelessly.
 *
 * **The cause survives in exactly one place**, `access_log.detail.reason`
 * (D-26, AC-50), which is the same role `login_history.failure_reason`
 * plays on the master side. An operator can tell the five causes apart; the
 * caller cannot.
 */
final class VerifyPinResult
{
    private function __construct(public readonly ?string $employeeId) {}

    /**
     * The PIN was right and the employee may act.
     */
    public static function verified(string $employeeId): self
    {
        return new self($employeeId);
    }

    /**
     * The one refusal, whatever the cause.
     */
    public static function refused(): self
    {
        return new self(null);
    }

    public function isVerified(): bool
    {
        return $this->employeeId !== null;
    }
}
