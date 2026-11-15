<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermission;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermissionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE /api/v1/access/employees/{employee}/permissions/{permissionKey}` - one per-person row removed (AC-33).
 *
 * Removing a row that is not there is `validation` and bumps nothing: a
 * silent success would tell a caller that a permission they never cleared
 * is gone.
 */
final class ClearEmployeePermissionController extends Controller
{
    public function __construct(private readonly ClearEmployeePermissionHandler $handler) {}

    public function __invoke(Request $request, string $employee, string $permissionKey): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $this->handler->handle(new ClearEmployeePermission(
                employeeId: $employee,
                permissionKey: $permissionKey,
                branchId: AccessInput::optionalText($request, 'branch_id'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(['employee_id' => $employee, 'permission_key' => $permissionKey, 'cleared' => true], 200);
    }
}
