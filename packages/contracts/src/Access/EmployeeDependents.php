<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * Who still depends on an employee, asked before the employee is
 * soft-deleted (T-038 plan Ruling 5, D-35).
 *
 * Implementations **count with locking reads** inside the caller's
 * transaction, and return **kinds only** (for example `department_head`,
 * `direct_reports`), never ids: the answer may travel on an in-process
 * refusal and must not widen what a caller learns.
 */
interface EmployeeDependents
{
    /**
     * @return list<string>
     */
    public function dependentKindsOf(string $employeeId): array;
}
