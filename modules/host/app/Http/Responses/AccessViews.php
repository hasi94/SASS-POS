<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Flexipos\Access\Support\EmployeeView;
use Flexipos\Access\Support\RoleView;

/**
 * The one renderer of this task's two read models (AC-23, AC-29, AC-65).
 *
 * **AC-65 is a property of what these types hold, and this class keeps it
 * that way.** {@see EmployeeView} has no `pin_hash`, no
 * `pin_failed_attempts`, no `pin_locked_until` and no `perm_version`: the
 * secrets are not omitted here, they were never in the object. What this
 * class adds is the guarantee that four endpoints render the same view
 * identically, so a field added to the view appears everywhere at once
 * rather than in whichever renderer was remembered.
 *
 * `has_pin` is the only thing about a PIN that leaves the database, and it
 * is a boolean: a till needs to know whether to ask for one, and nothing
 * needs to know what it is (AC-48).
 */
final class AccessViews
{
    /**
     * @return array<string, mixed>
     */
    public static function role(RoleView $role): array
    {
        return [
            'id' => $role->id,
            'key' => $role->key,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => $role->isSystem,
            'permission_keys' => $role->permissionKeys,
        ];
    }

    /**
     * @param  list<RoleView>  $roles
     * @return list<array<string, mixed>>
     */
    public static function roles(array $roles): array
    {
        return array_map(static fn (RoleView $role): array => self::role($role), $roles);
    }

    /**
     * @return array<string, mixed>
     */
    public static function employee(EmployeeView $employee): array
    {
        return [
            'id' => $employee->id,
            'code' => $employee->code,
            'first_name' => $employee->firstName,
            'last_name' => $employee->lastName,
            'display_name' => $employee->displayName,
            'status' => $employee->status,
            'has_pin' => $employee->hasPin,
            'roles' => array_map(
                static fn ($role): array => ['key' => $role->key, 'branch_id' => $role->branchId],
                $employee->roles,
            ),
        ];
    }

    /**
     * @param  list<EmployeeView>  $employees
     * @return list<array<string, mixed>>
     */
    public static function employees(array $employees): array
    {
        return array_map(static fn (EmployeeView $employee): array => self::employee($employee), $employees);
    }
}
