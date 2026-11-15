<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\DeleteEmployee;

/**
 * One request to soft-delete an employee (AC-28).
 *
 * Soft deletion is the only deletion this package offers: there is no
 * `force` and no second command. An employee's id is referenced by
 * `employee_roles.granted_by`, by `access_log` and by every audit row, and
 * a hard delete would break the history those exist to keep.
 */
final class DeleteEmployee
{
    public function __construct(
        public readonly string $employeeId,
        public readonly ?string $actorId = null,
    ) {}
}
