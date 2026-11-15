<?php

declare(strict_types=1);

namespace Flexipos\Auth\Exceptions;

use Flexipos\Contracts\Access\RefusalReason;
use RuntimeException;

/**
 * The membership names an employee the tenant database cannot serve
 * (T-010 AC-61, D-6).
 *
 * **One body for four causes, like {@see NoTenantAccess} and for the same
 * reason.** No employee row, a soft-deleted one, a status that cannot act,
 * and a `user_id` that is null or somebody else's are four different facts
 * about a shop's staff, and a caller who has merely presented a platform
 * token is entitled to none of them. The cause is in the application log at
 * warning level, written by the access package at the point it decides.
 *
 * **403 and not 401.** The caller has proved who they are; what they cannot
 * do is open this shop as this employee, and authenticating again would not
 * change that. It sits beside `no_access` and `tenant_not_servable` as the
 * third "you are known and the answer is still no".
 *
 * **It is a distinct reason from `no_access` on purpose.** Folding it in
 * would be cheaper and would hide the one case an operator has to be able
 * to find: a `tenant_users.employee_id` pointing at the wrong row. A
 * refusal nobody can distinguish is a defect nobody can diagnose, which is
 * the whole argument behind D-26's closed set.
 */
final class EmployeeUnavailable extends RuntimeException
{
    /**
     * The HTTP status (AC-61).
     */
    public const STATUS = 403;

    /**
     * The error token, which is {@see RefusalReason::EmployeeUnavailable}'s
     * own value rather than a second spelling of it: the access package
     * decides the vocabulary and this class renders it.
     */
    public const ERROR = 'employee_unavailable';

    public static function refused(): self
    {
        return new self('The membership names an employee that cannot be served.');
    }

    /**
     * @return array{error: string}
     */
    public function body(): array
    {
        return ['error' => RefusalReason::EmployeeUnavailable->value];
    }
}
