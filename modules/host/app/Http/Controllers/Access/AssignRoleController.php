<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/access/employees/{employee}/roles` - a role assigned to an employee, at a scope (AC-30, AC-31).
 *
 * `branch_id` absent means the company-wide scope, which is a stronger
 * grant than any single branch and is what the escalation guard checks the
 * actor against (AC-36).
 */
final class AssignRoleController extends Controller
{
    public function __construct(private readonly AssignRoleHandler $handler) {}

    public function __invoke(Request $request, string $employee): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $this->handler->handle(new AssignRole(
                employeeId: $employee,
                roleId: AccessInput::text($request, 'role_id'),
                branchId: AccessInput::optionalText($request, 'branch_id'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(['employee_id' => $employee, 'assigned' => true], 200);
    }
}
