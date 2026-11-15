<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignment;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignmentHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE /api/v1/access/employees/{employee}/roles/{role}` - one role assignment removed (AC-33).
 *
 * The scope is read from the body, because the same role may be assigned to
 * the same employee at several branches and the request has to say which one
 * it means. A DELETE with a body is unusual and it is the honest shape here:
 * the alternative is a scope in the query string, where a null scope and an
 * absent parameter are indistinguishable.
 */
final class RemoveRoleAssignmentController extends Controller
{
    public function __construct(private readonly RemoveRoleAssignmentHandler $handler) {}

    public function __invoke(Request $request, string $employee, string $role): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $this->handler->handle(new RemoveRoleAssignment(
                employeeId: $employee,
                roleId: $role,
                branchId: AccessInput::optionalText($request, 'branch_id'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(['employee_id' => $employee, 'removed' => true], 200);
    }
}
