<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * Who typed this PIN: the one question a supervisor override asks of access
 * (T-030 AC-39, D-30, F-5).
 *
 * **The answer is an employee id or null, and nothing else.** A wrong PIN, an
 * unknown employee code, an employee with no PIN, a locked employee and one who
 * may not act are indistinguishable to the caller (T-010 AC-50): a till told
 * "no such employee" rather than "wrong PIN" is an oracle that turns a 10^4
 * space into a 10^4-plus-codes space. The cause survives in `access_log` only.
 * A correct PIN proves **who** is deciding, never that they may; the caller
 * still asks `PermissionResolver` that.
 *
 * **Call it OUTSIDE any database transaction, and the contract says so on
 * purpose.** An attempt writes its failure counter and its `access_log` row in
 * a transaction of its own. Called inside a caller's transaction that then
 * rolls back (a self-approval refusal, say), that transaction becomes a
 * savepoint of the caller's and the rollback takes the counter increment with
 * it, so the lockout never fires and a guesser pays nothing for the failure.
 * The caller verifies first, then opens its transaction.
 *
 * Implemented in `packages/access`, which delegates to `VerifyPinHandler`.
 */
interface PinVerifier
{
    /**
     * @param  string  $employeeCode  the employee's code (not their id)
     * @param  string|null  $ip  the printed address of whoever is attempting, or null with no request behind the call
     * @return string|null the verified employee's id, or null for every refusal
     */
    public function verify(string $employeeCode, string $pin, ?string $ip = null): ?string;
}
