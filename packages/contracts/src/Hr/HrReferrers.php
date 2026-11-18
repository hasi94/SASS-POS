<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Hr;

/**
 * Who points at a department or a designation? (T-038 AC-25, AC-37, AC-86.)
 *
 * Implemented by each package that stores one of those ids (expenses,
 * payroll, workflow) and discovered through {@see DeclaresHrReferrers}.
 * Implementations **count with a locking read** (`sharedLock()`) inside the
 * caller's transaction. A kind with no referrer may be omitted or reported
 * as zero.
 */
interface HrReferrers
{
    /**
     * @return list<HrReferrer>
     */
    public function departmentReferrers(string $id): array;

    /**
     * @return list<HrReferrer>
     */
    public function designationReferrers(string $id): array;
}
