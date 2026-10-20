<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatus;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatusHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT /api/v1/access/employees/{employee}/status` - an employee's status (AC-27).
 *
 * Whether the change bumps `perm_version` is the handler's decision - it
 * bumps when the change crosses the acting boundary - and it is reported
 * rather than inferred, because that crossing is the fact AC-27 is about.
 */
final class ChangeEmployeeStatusController extends Controller
{
    public function __construct(private readonly ChangeEmployeeStatusHandler $handler) {}

    public function __invoke(Request $request, string $employee): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $result = $this->handler->handle(new ChangeEmployeeStatus(
                employeeId: $employee,
                status: AccessInput::text($request, 'status'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse([
            'employee_id' => $result->employeeId,
            'status' => $result->status,
            'bumped' => $result->bumped,
        ], 200);
    }
}
