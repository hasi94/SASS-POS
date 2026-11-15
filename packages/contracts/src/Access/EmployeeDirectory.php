<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * Does an employee exist, for a package that may not query employees
 * (T-014 plan section 3).
 *
 * Organization stores `manager_employee_id` on a location and must refuse an
 * id that names nobody, but `employees` is access's table. Implemented by
 * `packages/access`.
 */
interface EmployeeDirectory
{
    /**
     * True when an `employees` row holds this id and is not soft-deleted.
     */
    public function isLive(string $employeeId): bool;
}
