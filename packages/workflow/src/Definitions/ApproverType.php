<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Definitions;

/**
 * The full `workflow_steps.approver_type` vocabulary (`docs/database.md`
 * section 6.15, T-020 AC-9, D-13).
 *
 * Only {@see self::RESOLVABLE} resolve against real data today; the other
 * three are refused at definition-write time with `approverTypeUnavailable`
 * (Ruling 6c) and fail closed if a hand-inserted row ever reaches step
 * activation.
 */
enum ApproverType: string
{
    case Role = 'role';
    case Employee = 'employee';
    case ManagerOfRequester = 'manager_of_requester';
    case DepartmentHead = 'department_head';
    case BranchManager = 'branch_manager';
    case AnyOf = 'any_of';
    case AllOf = 'all_of';

    /**
     * @var list<self>
     */
    public const RESOLVABLE = [self::Role, self::Employee, self::AnyOf, self::AllOf];

    public function isResolvable(): bool
    {
        return in_array($this, self::RESOLVABLE, true);
    }
}
