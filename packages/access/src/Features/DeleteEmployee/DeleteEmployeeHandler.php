<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\DeleteEmployee;

use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\LastOwner;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Soft-delete an employee and bump them (AC-28, AC-52).
 *
 * **The bump matters even though the row is going away.** A deleted
 * employee may hold a live tenant token minted a minute ago; the resolver
 * refuses them from the moment `deleted_at` is set (AC-43), and the bump is
 * what tells the per-request staleness check (D-7) that the token's claims
 * are no longer the employee's. Without it the two guards would disagree
 * about when access ended.
 *
 * **Their grants are left exactly where they are.** `employee_roles` and
 * `employee_permissions` are untouched, so a restore - which is not an
 * operation this task ships - would find them intact, and an audit reader
 * can still see what somebody held. They confer nothing while
 * `deleted_at` is set, because the resolver refuses before it reads a
 * grant.
 *
 * **The code stays reserved** (AC-19's sibling rule for employees): the row
 * survives, the unique index covers it, and `CreateEmployee` refuses the
 * code for good.
 *
 * **AC-37's last-owner safeguard is now here and is unconditional**,
 * unlike the other two operations that carry it: a soft delete ends the
 * employee's ability to act whatever their status and whatever they hold,
 * so there is no shape of it that needs no check. {@see LastOwner} still
 * decides what an owner is, and returns without refusing when this
 * employee is not one.
 */
final class DeleteEmployeeHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly LastOwner $lastOwner,
        private readonly AuditWriter $audit,
    ) {}

    public function handle(DeleteEmployee $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            // Refuses an employee who is already deleted: a second delete is
            // `validation`, not a silent success, so a caller cannot read
            // "done" for an operation that did nothing.
            EmployeeLookup::findLive($connection, $command->employeeId);

            $this->lastOwner->requireAnother($connection, $command->employeeId);

            $now = $this->clock->timestamp();

            $connection->table('employees')
                ->where('id', $command->employeeId)
                ->update([
                    'deleted_at' => $now,
                    'updated_by' => $command->actorId,
                    'updated_at' => $now,
                ]);

            PermVersion::bump($connection, [$command->employeeId]);

            $this->audit->write(
                $connection,
                $command->actorId,
                'employee_deleted',
                'employee',
                $command->employeeId,
                ['deleted_at' => null],
                ['deleted_at' => $now],
            );
        });
    }
}
