<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SetPin;

use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\Escalation;
use Flexipos\Access\Support\PinPolicy;
use Flexipos\Access\Support\SelfChange;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Give an employee a PIN (AC-47), bounded by who is asking (T-014 AC-54 to
 * AC-59, T-010 D-39).
 *
 * **The order is the rule, and authorization comes first** (T-014 D-44):
 *
 * 1. No actor: `actor_required`, logged at error level, nothing read.
 * 2. The target is looked up (missing: `validation`).
 * 3. The actor being the target skips to 6: a PIN is a credential, and
 *    rotating one's own escalates nothing.
 * 4. A target holding no role with `roles.is_system = true` skips to 6.
 * 5. Otherwise the actor must hold everything the target holds
 *    ({@see Escalation::requireHoldsWhatTargetHolds()}), or `no_escalation`.
 * 6. {@see PinPolicy::accept()}: a weak PIN is `validation`.
 * 7. The write.
 *
 * Steps 1 to 5 sit before the policy so that a weak PIN cannot be used to
 * probe whether a target is protected: a target the actor may not reset gets
 * `no_escalation` whatever PIN was sent.
 *
 * **The hasher is the framework's, not a new one.** T-009 configured
 * `hashing.driver` as `argon2id` and `phpunit.xml` reduces its work factors
 * for the suite; a second hasher here would be a second algorithm decision
 * and a second place to get the factors wrong. The contract is injected
 * rather than the facade used, which is also what lets a test count
 * invocations (T-009 AC-8's shape) without replacing the hasher the
 * fixtures seed with.
 *
 * **Setting a PIN unlocks** (AC-47). `pin_failed_attempts` goes to zero and
 * `pin_locked_until` to null in the same statement that writes the hash,
 * because a manager resetting a locked cashier's PIN is the remedy for the
 * lockout: leaving the lock in place would mean the remedy did not work and
 * the shop waits fifteen minutes anyway. It is one statement so there is no
 * instant at which the new PIN exists under the old lock.
 *
 * **`perm_version` is not bumped, and that is a rule rather than an
 * omission** (AC-52's "not bumped" list). A bump tells every live token its
 * claims are stale; a PIN changes nothing about what its holder may do, so
 * a bump here would log every device out for a credential rotation.
 *
 * **No {@see SelfChange} guard, deliberately** (AC-34, in terms). An actor may
 * set their own PIN, so the self-change rule is *not* called here, and a
 * reader looking for it should read this paragraph rather than add it. What
 * replaced "any actor, any target" is the escalation rule above, which a
 * self-reset also skips.
 *
 * **Nothing it writes, logs or throws carries the PIN** (AC-48). The policy's
 * refusal messages name the rule and never the digits; the audit row records
 * the fact and the instant and neither the PIN nor the hash; the result
 * object carries no secret. The one column that holds the hash is
 * `employees.pin_hash`, which is the single expected exception in AC-48's
 * sweep.
 */
final class SetPinHandler
{
    /**
     * What `audit_logs.action` records for this operation (AC-70).
     */
    public const ACTION = 'pin_set';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly PinPolicy $policy,
        private readonly Hasher $hasher,
        private readonly RunnerClock $clock,
        private readonly AuditWriter $audit,
        private readonly Escalation $escalation,
    ) {}

    public function handle(SetPin $command): SetPinResult
    {
        // 1. Before anything is read.
        $actorId = $this->escalation->requireActor($command->actorId, 'set a PIN');

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $actorId): SetPinResult {
            // 2.
            $employee = EmployeeLookup::findLive($connection, $command->employeeId);

            // 3 to 5.
            if ($actorId !== $command->employeeId && SystemRole::isHeldBy($connection, $command->employeeId)) {
                $this->escalation->requireHoldsWhatTargetHolds($connection, $actorId, $command->employeeId);
            }

            // 6. Judged before the hasher, a row or a log line sees the value,
            // so a refused PIN never reaches any of them (AC-48's sixth sink).
            $pin = $this->policy->accept($command->pin);

            $now = $this->clock->timestamp();

            // 7.
            $connection->table('employees')
                ->where('id', $command->employeeId)
                ->update([
                    'pin_hash' => $this->hasher->make($pin),
                    'pin_set_at' => $now,
                    'pin_failed_attempts' => 0,
                    'pin_locked_until' => null,
                    'updated_by' => $actorId,
                    'updated_at' => $now,
                ]);

            // AC-70, and the whole of what the row may say: that a PIN was
            // set, for whom, by whom and when. `before` and `after` hold the
            // instant, never the secret - `pin_set_at` moving is the change,
            // and `changed_fields` derives from it.
            $this->audit->write(
                $connection,
                $actorId,
                self::ACTION,
                'employee',
                $command->employeeId,
                ['pin_set_at' => $employee->pin_set_at],
                ['pin_set_at' => $now],
            );

            return new SetPinResult($command->employeeId, $now);
        });
    }
}
