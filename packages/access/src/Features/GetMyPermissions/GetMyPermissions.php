<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\GetMyPermissions;

use Flexipos\Access\Features\GetEmployee;

/**
 * One request for the caller's own effective access (AC-60).
 *
 * **The employee is nullable and that is the criterion, not laxness.** A
 * membership with no employee mints a token with no `employee` claim
 * (T-009 D-18, AC-58), and such a caller is entitled to ask this question
 * and to be told "nothing" - it is the one route they can reach. A command
 * that demanded an employee would force the controller to decide what to
 * answer, which is the decision this slice owns.
 *
 * **There is no subject here, only a caller.** This is deliberately not
 * "show me employee X's permissions": that question belongs to
 * `employees.view` and to {@see GetEmployee}.
 * Nothing in this command can name somebody else, which is why the route
 * needs no permission key (AC-63's one `authenticated_only`).
 */
final class GetMyPermissions
{
    public function __construct(public readonly ?string $employeeId) {}
}
