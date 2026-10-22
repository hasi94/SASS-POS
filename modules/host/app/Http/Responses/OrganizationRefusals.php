<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Support\OrganizationRefusal;
use Illuminate\Http\JsonResponse;

/**
 * Every refusal the organization endpoints answer with, built in one place
 * (T-014 Ruling 8, AC-63).
 *
 * The shapes, which a test iterates over every code to assert:
 *
 * - `unknown_branch` and `unknown_terminal`: 422
 *   `{"error":"validation","reason":"unknown_branch"}`. A submission the
 *   caller can correct, so it keeps validation's status and body and names
 *   the part that was wrong.
 * - `not_found`: 404 `{"error":"not_found"}`. The first 404 in the API; the
 *   only sensible code for an id that names nothing in this tenant.
 * - `validation`: 422 `{"error":"validation"}`.
 * - every state refusal (`branch_inactive`, `has_active_terminals`,
 *   `last_branch`, `location_in_use`, `terminal_not_pending`,
 *   `terminal_not_active`, `terminal_revoked`, `scope_not_supported`,
 *   `actor_required`): 403 `{"error":"<code>"}`, following T-010's
 *   `last_owner` and `role_in_use`.
 *
 * **The message of the exception is never rendered**: it is operator-facing
 * and may name ids. The status comes from {@see OrganizationRefusal::status()},
 * so the enum and the wire cannot disagree.
 */
final class OrganizationRefusals
{
    public static function refused(OrganizationRefused $refused): JsonResponse
    {
        return self::of($refused->reason);
    }

    public static function of(OrganizationRefusal $reason): JsonResponse
    {
        if ($reason === OrganizationRefusal::UnknownBranch || $reason === OrganizationRefusal::UnknownTerminal) {
            return new JsonResponse(['error' => OrganizationRefusal::Validation->value, 'reason' => $reason->value], $reason->status());
        }

        return new JsonResponse(['error' => $reason->value], $reason->status());
    }
}
