<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\VerifyPin;

use Carbon\CarbonImmutable;
use Flexipos\Access\Support\AccessLogWriter;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\PinPolicy;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Connection;

/**
 * Verify a cashier's PIN, in process, and count the failures (AC-49, AC-50,
 * AC-51).
 *
 * **One refusal to the caller, five causes in the log.** The result type
 * carries no cause at all ({@see VerifyPinResult}); which of AC-50's five
 * applied is written to `access_log.detail.reason` and lives nowhere else.
 * A till that could tell "no such code" from "wrong PIN" would turn the
 * guessing space from 10^4 PINs into 10^4 PINs times the codes it could
 * enumerate for free.
 *
 * **The hasher runs exactly once in every case, including the ones with
 * nothing to compare against.** An unknown code and an employee with no PIN
 * are checked against {@see self::DUMMY_PIN_HASH}, so the work - and
 * therefore the time - does not depend on whether the code matched. The
 * check is made *before* any decision, rather than inside the branches,
 * because an `if` per branch is how one of them comes to be missed: this
 * way the count is one by construction and a test can assert it is one for
 * every case. T-009 AC-8 is the same criterion on the master side, and AC-50
 * names a counting double that reports zero for the unknown-code case as the
 * negative control that must turn it red.
 *
 * **The lockout is one statement, and that is the `[ENGINE]` half of AC-51
 * made reviewable in the diff.** A read of `pin_failed_attempts` followed by
 * a write of `+1` loses increments under concurrency and can lock twice; the
 * `UPDATE` below computes both columns from the stored value in a single
 * statement, so two simultaneous wrong PINs raise the counter by two and the
 * lock lands once. The concurrent proof itself waits for an engine (G-1);
 * what is provable here is the shape.
 *
 * **A failure while locked neither counts nor postpones** (D-17). The locked
 * branch returns before the counter is touched, which is why "the counter
 * stays 0 and `pin_locked_until` is not extended" needs no code to be true -
 * and is asserted anyway, because an absence nobody checks is an absence
 * nobody notices disappearing.
 *
 * **Nothing here reads or writes `perm_version`, and nothing writes
 * `audit_logs`.** A PIN attempt is not an administrative operation (AC-70's
 * list) and not an access change (AC-52's "not bumped" list); it leaves
 * exactly one `access_log` row and, at most, a counter.
 */
final class VerifyPinHandler
{
    /**
     * The five causes AC-50 closes the set at.
     *
     * One list, here, because the writer, the tests and any later report
     * must agree about what a `pin_failed` row can say.
     */
    public const REASON_UNKNOWN_EMPLOYEE = 'unknown_employee';

    public const REASON_BAD_PIN = 'bad_pin';

    public const REASON_NO_PIN = 'no_pin';

    public const REASON_LOCKED = 'locked';

    public const REASON_NOT_ACTING = 'not_acting';

    /**
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_UNKNOWN_EMPLOYEE,
        self::REASON_BAD_PIN,
        self::REASON_NO_PIN,
        self::REASON_LOCKED,
        self::REASON_NOT_ACTING,
    ];

    /**
     * The fixed digest an attempt is checked against when there is no stored
     * one (AC-50).
     *
     * Produced by `password_hash(bin2hex(random_bytes(32)),
     * PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4,
     * 'threads' => 1])` - the shipped work factors, so the two paths cost the
     * same in production - with the random plaintext discarded at the moment
     * it was generated and written down nowhere. **It is not a secret and
     * not key material:** it authenticates nothing, and no PIN can be
     * submitted that verifies against it.
     *
     * Fixed rather than generated per process, exactly as
     * `LoginHandler::DUMMY_PASSWORD_HASH` is and for the same two reasons: a
     * digest built at boot costs a hash per process and could differ in
     * parameters from the stored ones, and a constant is something a test can
     * name to prove the check happened against this and nothing else.
     */
    public const DUMMY_PIN_HASH = '$argon2id$v=19$m=65536,t=4,p=1$Ujc5VW9qejRra0hsdVlFaA$z65rpOUbjkiAJawSwfmZ0wfv/exwGxP1SAvpvwGu+Ro';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly EmployeeStatus $statuses,
        private readonly PinPolicy $policy,
        private readonly Hasher $hasher,
        private readonly RunnerClock $clock,
        private readonly AccessLogWriter $log,
    ) {}

    public function handle(VerifyPin $command): VerifyPinResult
    {
        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command): VerifyPinResult {
            /** @var object{id: string, status: string, pin_hash: ?string, pin_locked_until: ?string}|null $employee */
            $employee = $connection->table('employees')
                ->where('code', $command->code)
                ->whereNull('deleted_at')
                ->first();

            // Once, always, before any branch. A soft-deleted employee is
            // not found by the query above, so they take the unknown-code
            // path: they are not there, which is the same fact the code
            // matching nobody states.
            $stored = $employee === null ? null : $employee->pin_hash;

            $matches = $this->hasher->check($command->pin, $stored ?? self::DUMMY_PIN_HASH);

            if ($employee === null) {
                return $this->refuse($connection, null, self::REASON_UNKNOWN_EMPLOYEE, $command->ip);
            }

            $employeeId = (string) $employee->id;

            if (! $this->statuses->isActing((string) $employee->status)) {
                return $this->refuse($connection, $employeeId, self::REASON_NOT_ACTING, $command->ip);
            }

            if ($this->isLocked($employee->pin_locked_until)) {
                // Nothing is written to the employee here: a failure while
                // locked is not counted and the lock is not extended (D-17).
                return $this->refuse($connection, $employeeId, self::REASON_LOCKED, $command->ip);
            }

            if ($employee->pin_hash === null) {
                return $this->refuse($connection, $employeeId, self::REASON_NO_PIN, $command->ip);
            }

            if (! $matches) {
                $this->countFailure($connection, $employeeId);

                return $this->refuse($connection, $employeeId, self::REASON_BAD_PIN, $command->ip);
            }

            // AC-49: a success clears the counter, which is what makes "four
            // wrong then a right one" leave nothing behind for the next four.
            $connection->table('employees')
                ->where('id', $employeeId)
                ->update(['pin_failed_attempts' => 0]);

            $this->log->pinLogin($connection, $employeeId, $command->ip);

            return VerifyPinResult::verified($employeeId);
        });
    }

    /**
     * Is the stored lock still in force at this instant?
     *
     * Strictly less than, so the boundary falls the way AC-51 states it: at
     * `pin_locked_until` minus one second the PIN is refused, at exactly
     * `pin_locked_until` it is accepted.
     */
    private function isLocked(?string $lockedUntil): bool
    {
        if ($lockedUntil === null) {
            return false;
        }

        return $this->clock->now()->lessThan(CarbonImmutable::parse($lockedUntil));
    }

    /**
     * Count one wrong PIN, and lock on the threshold - in one statement.
     *
     * Both columns are computed from the value stored when the statement
     * began: `pin_locked_until` is assigned before `pin_failed_attempts`,
     * which matters on MySQL and MariaDB, where later assignments in an
     * `UPDATE` see the values written by earlier ones. SQLite evaluates
     * every right-hand side against the original row, so the order is
     * harmless there and necessary here.
     *
     * The counter resets **at the lock** rather than at the unlock (D-17):
     * the lock itself is the record that the threshold was reached, and a
     * counter left at five would lock the employee again on their first
     * wrong PIN after the lock expired.
     */
    private function countFailure(Connection $connection, string $employeeId): void
    {
        $threshold = $this->policy->lockoutAfter();
        $until = $this->clock->timestampInMinutes($this->policy->lockoutMinutes());

        $connection->update(
            'update employees set '
            .'pin_locked_until = case when pin_failed_attempts + 1 >= ? then ? else pin_locked_until end, '
            .'pin_failed_attempts = case when pin_failed_attempts + 1 >= ? then 0 else pin_failed_attempts + 1 end '
            .'where id = ?',
            [$threshold, $until, $threshold, $employeeId]
        );
    }

    /**
     * Write the one `pin_failed` row and hand back the one refusal.
     */
    private function refuse(Connection $connection, ?string $employeeId, string $reason, ?string $ip): VerifyPinResult
    {
        $this->log->pinFailed($connection, $employeeId, $reason, $ip);

        return VerifyPinResult::refused();
    }
}
