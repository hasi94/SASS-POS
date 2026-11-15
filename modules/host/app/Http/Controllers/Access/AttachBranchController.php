<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\AttachBranch\AttachBranch;
use Flexipos\Access\Features\AttachBranch\AttachBranchHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/access/employees/{employee}/branches` - an employee attached to a branch (AC-35).
 *
 * A well-formed branch id naming no branch is **accepted**: there is no
 * `branches` table to check against (D-30, FP-209), and this task validates
 * the shape of the id and nothing else.
 */
final class AttachBranchController extends Controller
{
    public function __construct(private readonly AttachBranchHandler $handler) {}

    public function __invoke(Request $request, string $employee): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $this->handler->handle(new AttachBranch(
                employeeId: $employee,
                branchId: AccessInput::text($request, 'branch_id'),
                isDefault: AccessInput::flag($request, 'is_default'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(['employee_id' => $employee, 'attached' => true], 200);
    }
}
