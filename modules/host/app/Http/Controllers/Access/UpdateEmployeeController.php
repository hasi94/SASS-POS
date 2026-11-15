<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use App\Http\Responses\AccessViews;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployee;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployeeHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PATCH /api/v1/access/employees/{employee}` - an employee's names (AC-26).
 *
 * **The whole submitted field set is handed to the handler, deliberately.**
 * AC-26 refuses an update naming `code`, `user_id`, `pin_hash`,
 * `perm_version` or `status`, and a controller that picked out the three
 * allowed fields would turn that refusal into the silent ignore the
 * criterion exists to forbid - indistinguishable from a field nobody wired.
 */
final class UpdateEmployeeController extends Controller
{
    public function __construct(private readonly UpdateEmployeeHandler $handler) {}

    public function __invoke(Request $request, string $employee): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $view = $this->handler->handle(new UpdateEmployee(
                employeeId: $employee,
                fields: AccessInput::fields($request),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(AccessViews::employee($view), 200);
    }
}
