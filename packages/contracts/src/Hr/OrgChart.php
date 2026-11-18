<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Hr;

/**
 * Who reports to whom, and who heads which department, for a package that
 * may not read HR tables (T-038 AC-82, D-18).
 *
 * Read-only, and none of it is sensitive. FP-358's approver types consume it.
 * Implemented by `packages/hr`; {@see NoOrgChart} is the answer-nothing
 * default for tests and consumers' fixtures (D-52).
 */
interface OrgChart
{
    /** The employee's `reports_to_employee_id`, or null (no profile, deleted employee, deleted manager). */
    public function managerOf(string $employeeId): ?string;

    /** The head of the employee's own department, never inherited from an ancestor. */
    public function departmentHeadOf(string $employeeId): ?string;

    public function departmentOf(string $employeeId): ?string;

    public function designationOf(string $employeeId): ?string;

    /** Live employees reporting to the employee, in id order, at most {@see DirectReports::MAXIMUM}. */
    public function directReportsOf(string $employeeId): DirectReports;

    public function isLiveDepartment(string $id): bool;

    public function isLiveDesignation(string $id): bool;
}
