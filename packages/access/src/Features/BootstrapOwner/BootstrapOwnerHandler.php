<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\BootstrapOwner;

use Flexipos\Access\Features\AttachBranch\AttachBranch;
use Flexipos\Access\Features\AttachBranch\AttachBranchHandler;
use Flexipos\Access\Features\CreateEmployee\CreateEmployee;
use Flexipos\Access\Features\CreateEmployee\CreateEmployeeHandler;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Contracts\Access\OwnerSeed;
use Flexipos\Contracts\Access\TenantAccessBootstrap;
use Flexipos\Contracts\Access\TenantBootstrapRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;

/**
 * {@see TenantAccessBootstrap}, the one door `packages/platform`'s
 * `create_owner` step is allowed to reach into this package through (T-026
 * D-23, D-24, Ruling 5, Ruling 7).
 *
 * **`grantOwner()` is the D-23 operation.** It never calls `Escalation`, and
 * its precondition - no **other** live employee in this tenant already
 * holds the system `owner` role - is checked inside the same transaction as
 * the write, which is its whole guard. A second call for the same employee
 * who already holds it is a no-op, not a second `employee_roles` row: the
 * unique index over `(employee_id, role_id, branch_id_key)` would refuse a
 * second null-scope row for the same pair anyway, but this method answers
 * idempotently before ever reaching it.
 *
 * **`ensureOwnerEmployee()` and `attachOwnerBranch()` are findable by a fixed
 * key, not a foreign reference to this run.** `employees.code = 'OWNER'`
 * (D-24) is the one literal this step always uses, so a resumed
 * `create_owner` finds the same row a crashed attempt already created.
 */
final class BootstrapOwnerHandler implements TenantAccessBootstrap
{
    /** The one employee code this operation ever creates or looks for (D-24). */
    public const OWNER_CODE = 'OWNER';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditWriter $audit,
        private readonly PermissionRegistry $registry,
        private readonly SyncPermissionRegistryHandler $syncPermissions,
        private readonly CreateEmployeeHandler $createEmployee,
        private readonly AttachBranchHandler $attachBranch,
    ) {}

    public function seedPermissions(): array
    {
        $result = $this->syncPermissions->handle(new SyncPermissionRegistry);

        return $result->refused;
    }

    public function ensureOwnerEmployee(OwnerSeed $seed): string
    {
        $connection = $this->tenant->get();

        $existing = $connection->table('employees')
            ->where('code', self::OWNER_CODE)
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null) {
            /** @var object{id: string} $existing */
            return (string) $existing->id;
        }

        $view = $this->createEmployee->handle(new CreateEmployee(
            code: self::OWNER_CODE,
            firstName: $seed->firstName,
            displayName: $seed->displayName,
        ));

        return $view->id;
    }

    public function attachOwnerBranch(string $employeeId, string $branchId): void
    {
        $connection = $this->tenant->get();

        $attached = $connection->table('employee_branches')
            ->where('employee_id', $employeeId)
            ->where('branch_id', $branchId)
            ->exists();

        if ($attached) {
            return;
        }

        $this->attachBranch->handle(new AttachBranch($employeeId, $branchId, isDefault: true, actorId: null));
    }

    public function grantOwner(string $employeeId): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $employeeId): void {
            $owner = $connection->table('roles')
                ->where('key', SystemRole::OWNER_KEY)
                ->where('is_system', true)
                ->first();

            if ($owner === null) {
                throw TenantBootstrapRefused::noOwnerRole(
                    'No system role keyed [owner] exists yet in this tenant; seedPermissions() must run first.'
                );
            }

            /** @var object{id: string} $owner */
            $ownerId = (string) $owner->id;

            $alreadyHeldByThis = $connection->table('employee_roles')
                ->where('employee_id', $employeeId)
                ->where('role_id', $ownerId)
                ->exists();

            if ($alreadyHeldByThis) {
                // Idempotent: a resumed create_owner re-entering this step.
                return;
            }

            $heldByAnotherLiveEmployee = $connection->table('employee_roles')
                ->join('employees', 'employees.id', '=', 'employee_roles.employee_id')
                ->where('employee_roles.role_id', $ownerId)
                ->where('employee_roles.employee_id', '!=', $employeeId)
                ->whereNull('employees.deleted_at')
                ->exists();

            if ($heldByAnotherLiveEmployee) {
                throw TenantBootstrapRefused::ownerAlreadyHeldByAnother(
                    "Employee [{$employeeId}] may not be granted [owner]: another live employee already holds it."
                );
            }

            $connection->table('employee_roles')->insert([
                'id' => (string) Str::ulid(),
                'employee_id' => $employeeId,
                'role_id' => $ownerId,
                'branch_id' => null,
                'granted_by' => null,
                'granted_at' => $this->clock->timestamp(),
            ]);

            PermVersion::bump($connection, [$employeeId]);

            $this->audit->write(
                $connection,
                null,
                'owner_granted',
                'employee',
                $employeeId,
                null,
                ['role_id' => $ownerId],
            );
        });
    }

    public function linkUser(string $employeeId, string $userId): void
    {
        $connection = $this->tenant->get();

        $employee = $connection->table('employees')->where('id', $employeeId)->first();

        if ($employee === null) {
            throw TenantBootstrapRefused::noOwnerRole(
                "No employee has id [{$employeeId}]; linkUser() has nothing to link."
            );
        }

        /** @var object{user_id: ?string} $employee */
        if ((string) $employee->user_id === $userId) {
            // Idempotent: already linked to this exact user.
            return;
        }

        $connection->table('employees')
            ->where('id', $employeeId)
            ->update(['user_id' => $userId, 'updated_at' => $this->clock->timestamp()]);
    }

    public function ownerMissingKeys(): array
    {
        $connection = $this->tenant->get();

        $declared = array_map(
            static fn ($declaration): string => $declaration->key,
            $this->registry->declarations()
        );

        $owner = $connection->table('roles')
            ->where('key', SystemRole::OWNER_KEY)
            ->where('is_system', true)
            ->first();

        if ($owner === null) {
            $missing = $declared;
            sort($missing);

            return $missing;
        }

        /** @var object{id: string} $owner */
        $held = $connection->table('role_permissions')
            ->where('role_id', $owner->id)
            ->pluck('permission_key')
            ->map(static fn ($key): string => (string) $key)
            ->all();

        $missing = array_values(array_diff($declared, $held));
        sort($missing);

        return $missing;
    }

    public function ownerState(): ?array
    {
        $connection = $this->tenant->get();

        $employee = $connection->table('employees')
            ->where('code', self::OWNER_CODE)
            ->whereNull('deleted_at')
            ->first();

        if ($employee === null) {
            return null;
        }

        /** @var object{id: string, user_id: ?string} $employee */
        $branchIds = $connection->table('employee_branches')
            ->where('employee_id', $employee->id)
            ->orderBy('branch_id')
            ->pluck('branch_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return [
            'employee_id' => (string) $employee->id,
            'user_id' => $employee->user_id === null ? null : (string) $employee->user_id,
            'branch_ids' => $branchIds,
            'holds_owner' => SystemRole::isHeldBy($connection, (string) $employee->id),
        ];
    }
}
