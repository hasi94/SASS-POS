<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ChangeEmployeeStatus;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\EmployeeStatus;
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
 * AC-37's last-owner safeguard also refuses this operation when it would
 * leave a tenant with no acting owner. It is step 14's work and is
 * deliberately absent here rather than stubbed: a guard written to permit
 * is worse than one not yet written, because it reads as present.
 */
final class ChangeEmployeeStatusHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly EmployeeStatus $statuses,
        private readonly RunnerClock $clock,
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

            $connection->table('employees')
                ->where('id', $command->employeeId)
                ->update([
                    'status' => $status,
                    'updated_by' => $command->actorId,
                    'updated_at' => $this->clock->timestamp(),
                ]);

            if ($crosses) {
                // In the same transaction as the change, one atomic
                // statement, never read-then-write.
                PermVersion::bump($connection, [$command->employeeId]);
            }

            return new ChangeEmployeeStatusResult($command->employeeId, $from, $status, $crosses);
        });
    }
}
