<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Contracts\Access\EmployeeDirectory;

/**
 * {@see EmployeeDirectory} over the tenant database (T-014 AC-21).
 *
 * "Live" is the definition every access operation already uses
 * ({@see EmployeeLookup::findLive()}): a row that exists and is not
 * soft-deleted. Status is deliberately not consulted: a suspended person can
 * still be named as a location's manager, and their return to the acting set
 * is a separate decision.
 */
final class DatabaseEmployeeDirectory implements EmployeeDirectory
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function isLive(string $employeeId): bool
    {
        return $this->tenant->get()->table('employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->exists();
    }
}
