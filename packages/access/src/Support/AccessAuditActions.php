<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

/**
 * The fifteen `audit_logs.action` literals the T-010 handlers already write
 * through {@see AuditWriter::write()} (T-017 Ruling 3).
 *
 * **A declared catalogue, not a rewrite of those handlers.** The plan's
 * alternatives rejected "replacing the 15 access action literals with
 * constants" (plan section 2): it would touch fourteen reviewed T-010
 * handlers for no behavioural gain once the writer enforces this list. So
 * this class states, once, what every one of those handlers already passes -
 * {@see AuditWriter::insert()} is what makes the list trustworthy, by
 * refusing anything it does not name.
 */
final class AccessAuditActions
{
    /**
     * The module every action below belongs to - {@see AuditWriter::MODULE}.
     */
    public const MODULE = 'access';

    /**
     * @var list<string>
     */
    public const ALL = [
        // AssignRoleHandler
        'role_assigned',
        // AttachBranchHandler
        'branch_attached',
        // ChangeEmployeeStatusHandler
        'employee_status_changed',
        // ClearEmployeePermissionHandler
        'employee_permission_cleared',
        // CreateEmployeeHandler
        'employee_created',
        // CreateRoleHandler
        'role_created',
        // DeleteEmployeeHandler
        'employee_deleted',
        // DeleteRoleHandler
        'role_deleted',
        // DetachBranchHandler
        'branch_detached',
        // RemoveRoleAssignmentHandler
        'role_assignment_removed',
        // RenameRoleHandler
        'role_renamed',
        // SetEmployeePermissionHandler
        'employee_permission_set',
        // SetPinHandler
        'pin_set',
        // SetRolePermissionsHandler
        'role_permissions_set',
        // UpdateEmployeeHandler
        'employee_updated',
    ];
}
