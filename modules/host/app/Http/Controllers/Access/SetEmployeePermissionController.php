<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermission;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermissionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT /api/v1/access/employees/{employee}/permissions/{permissionKey}` - one per-person allow or deny (AC-32).
 *
 * `allowed` is required and must be a real boolean: an allow and a deny are
 * different operations - only the allow is checked against the actor's own
 * rights (AC-36) - so a missing or misspelled flag must not default to
 * either.
 */
final class SetEmployeePermissionController extends Controller
{
    public function __construct(private readonly SetEmployeePermissionHandler $handler) {}

    public function __invoke(Request $request, string $employee, string $permissionKey): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        $allowed = AccessInput::raw($request, 'allowed');

        try {
            if (! is_bool($allowed)) {
                throw AccessRefused::validation('[allowed] must be submitted as true or false.');
            }

            $this->handler->handle(new SetEmployeePermission(
                employeeId: $employee,
                permissionKey: $permissionKey,
                allowed: $allowed,
                branchId: AccessInput::optionalText($request, 'branch_id'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(['employee_id' => $employee, 'permission_key' => $permissionKey, 'allowed' => $allowed], 200);
    }
}
