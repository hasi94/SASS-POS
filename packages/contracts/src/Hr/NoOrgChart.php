<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Hr;

/**
 * The org chart that knows nobody (T-038 AC-84, D-52).
 *
 * Every question answers "none": null, an empty untruncated list, false. It
 * sits beside the interface so a consumer's fixture need not import HR.
 */
final class NoOrgChart implements OrgChart
{
    public function managerOf(string $employeeId): ?string
    {
        return null;
    }

    public function departmentHeadOf(string $employeeId): ?string
    {
        return null;
    }

    public function departmentOf(string $employeeId): ?string
    {
        return null;
    }

    public function designationOf(string $employeeId): ?string
    {
        return null;
    }

    public function directReportsOf(string $employeeId): DirectReports
    {
        return new DirectReports([], false);
    }

    public function isLiveDepartment(string $id): bool
    {
        return false;
    }

    public function isLiveDesignation(string $id): bool
    {
        return false;
    }
}
