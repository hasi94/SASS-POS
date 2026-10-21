<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ChangeEmployeeStatus;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\Escalation;
use Flexipos\Access\Support\LastOwner;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Move an employee to another status, and bump only when that crosses the
 * acting boundary (AC-27, AC-52).
 *
 * **Crossing, not changing.** `active` to `probation` changes the stored
 * word and changes nothing about what the person may do, so it must not
 * bump: a bump tells every live token its claims are stale. `active` to
 * `on_leave` ends their ability to act, and `suspended` to `probation`
 * restores it; both directions bump, which is why the test is the boundary
 * condition `isActing($from) !== isActing($to)` rather than a list of four
 * transitions.
 *
 * **The acting set is asked for, never restated.** {@see EmployeeStatus} is
 * injected and is the single authority (D-15); this handler contains no
 * `['active', 'probation']`, and AC-27's moved-value case is what proves
 * it - move the set and a transition that crossed the boundary stops
 * crossing it.
 *
 * **The status is read inside the transaction the bump lands in.** Reading
 * `from` outside it would decide whether to bump against a status that
 * another writer may have moved in between, and the bump would then be
 * attached to a crossing that did not happen.
 *
 * **AC-37's last-owner safeguard is now here, and it is asked exactly when
 * the change ends the ability to act.** `active` to `probation` moves
 * nobody out of the acting set, so the only acting owner may make it;
 * `active` to `on_leave` would leave the shop with nobody who can
 * administer it, and is refused. The condition is therefore the same
 * boundary crossing the bump already turns on, in the leaving direction -
 * one fact, read once, in the direction that matters.
 *
 * **The entering direction is escalation-checked (D-38, AC-27).** Moving
 * somebody from a non-acting status into the acting set restores every
 * grant they hold, so it is a grant of all of them: the actor must hold
 * each one at its scope, asked through {@see Escalation} like every other
 * grant, and a null actor is `actor_required`. Leaving, and moves within
 * one side, take nothing from anybody's reach and stay unchecked - the
 * actor who could not reactivate an employee may still suspend them.
 */
final class ChangeEmployeeStatusHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly EmployeeStatus $statuses,
        private readonly RunnerClock $clock,
        private readonly LastOwner $lastOwner,
        private readonly Escalation $escalation,
        private readonly AuditWriter $audit,
    ) {}

    public function handle(ChangeEmployeeStatus $command): ChangeEmployeeStatusResult
    {
        $status = trim($command->status);

        if (! EmployeeStatus::exists($status)) {
            throw AccessRefused::validation("Employee status [{$status}] is not one of the declared statuses.");
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $status): ChangeEmployeeStatusResult {
            $employee = EmployeeLookup::findLive($connection, $command->employeeId);

            $from = (string) $employee->status;

            if ($from === $status) {
                // A no-op, not a refusal: no document forbids re-sending the
                // status somebody already holds, and AC-53 (c) says only
                // that a change implies a bump.
                return new ChangeEmployeeStatusResult($command->employeeId, $from, $status, false);
            }

            $crosses = $this->statuses->isActing($from) !== $this->statuses->isActing($status);

            if ($crosses && ! $this->statuses->isActing($status)) {
                // AC-37: only the leaving direction can remove an owner.
                $this->lastOwner->requireAnother($connection, $command->employeeId);
            }

            if ($crosses && $this->statuses->isActing($status)) {
                // D-38: the entering direction restores every grant, so the
                // actor must hold each one. Before any write, so a refusal
                // leaves the status, the version and the audit log alone.
                $this->escalation->requireEmployeeHeld($connection, $command->actorId, $command->employeeId);
            }

            $connection->table('employees')
                ->where('id', $command->employeeId)
                ->update([
                    'status' => $status,
                    'updated_by' => $command->actorId,
                    'updated_at' => $this->clock->timestamp(),
                ]);

            $this->audit->write(
                $connection,
                $command->actorId,
                'employee_status_changed',
                'employee',
                $command->employeeId,
                ['status' => $from],
                ['status' => $status],
            );

            if ($crosses) {
                // In the same transaction as the change, one atomic
                // statement, never read-then-write.
                PermVersion::bump($connection, [$command->employeeId]);
            }

            return new ChangeEmployeeStatusResult($command->employeeId, $from, $status, $crosses);
        });
    }
}
