<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\GetEmployee;

use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\EmployeeView;
use Flexipos\Access\Support\TenantConnection;

/**
 * One employee, with the roles they hold and the scopes they hold them at
 * (AC-29).
 *
 * A soft-deleted employee is refused, not returned with a flag:
 * {@see EmployeeLookup::findLive()} decides what "live" means for every
 * slice at once, so the detail query and the four mutating operations
 * cannot disagree about whether a deleted employee exists.
 */
final class GetEmployeeHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function handle(GetEmployee $query): EmployeeView
    {
        $connection = $this->tenant->get();

        $employee = EmployeeLookup::findLive($connection, $query->employeeId);

        $views = EmployeeLookup::render($connection, [$employee]);

        return $views[0];
    }
}
