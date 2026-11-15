<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\DetachBranch\DetachBranch;
use Flexipos\Access\Features\DetachBranch\DetachBranchHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE /api/v1/access/employees/{employee}/branches/{branch}` - a branch attachment removed (AC-35).
 *
 * Refused `has_scoped_grants` while any role assignment or per-person row
 * for that employee is scoped to the branch: the two halves of D-11 keep
 * "every scoped grant names an attached branch" true at all times.
 */
final class DetachBranchController extends Controller
{
    public function __construct(private readonly DetachBranchHandler $handler) {}

    public function __invoke(Request $request, string $employee, string $branch): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $this->handler->handle(new DetachBranch($employee, $branch, $actorId));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(['employee_id' => $employee, 'detached' => true], 200);
    }
}
