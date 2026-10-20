<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployee;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployeeHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE /api/v1/access/employees/{employee}` - an employee, soft-deleted (AC-28).
 *
 * The only deletion there is: the row stays, `deleted_at` is set, the code
 * stays reserved and the last acting owner cannot be removed this way
 * (`last_owner`).
 */
final class DeleteEmployeeController extends Controller
{
    public function __construct(private readonly DeleteEmployeeHandler $handler) {}

    public function __invoke(Request $request, string $employee): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $this->handler->handle(new DeleteEmployee($employee, $actorId));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(['employee_id' => $employee, 'deleted' => true], 200);
    }
}
