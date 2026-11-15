<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\BuildEmployeeClaims;

/**
 * One request for the five employee-derived token claims (D-2, D-6).
 *
 * Two identifiers, and the second is the point. `userId` is the subject of
 * the platform token that is selecting a shop, and D-6's fourth check is
 * that `employees.user_id` equals it: a `tenant_users.employee_id` pointing
 * at somebody else's employee row would otherwise hand one person another
 * person's permissions, silently and with a valid token. Both come from
 * server-side state - the membership row and the verified token - and
 * neither can be submitted.
 */
final class BuildEmployeeClaims
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $userId,
    ) {}
}
