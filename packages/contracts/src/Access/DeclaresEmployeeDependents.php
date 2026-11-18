<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * Implemented by a package's service provider to declare the
 * {@see EmployeeDependents} it provides (T-038 plan Ruling 5).
 *
 * The same sweep shape as `DeclaresAuditActions`: access walks the loaded
 * providers and never names another package. No provider declaring one is the
 * "none" default.
 */
interface DeclaresEmployeeDependents
{
    /**
     * @return list<class-string<EmployeeDependents>>
     */
    public function employeeDependents(): array;
}
