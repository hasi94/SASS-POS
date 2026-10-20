<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use App\Http\Responses\AccessViews;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\CreateEmployee\CreateEmployee;
use Flexipos\Access\Features\CreateEmployee\CreateEmployeeHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/access/employees` - a new employee (AC-24, AC-25).
 *
 * `code` and `first_name` are required; `display_name` defaults in the
 * handler, not here, so the one rule for it lives beside the row it writes.
 */
final class CreateEmployeeController extends Controller
{
    public function __construct(private readonly CreateEmployeeHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $view = $this->handler->handle(new CreateEmployee(
                code: AccessInput::text($request, 'code'),
                firstName: AccessInput::text($request, 'first_name'),
                lastName: AccessInput::optionalText($request, 'last_name'),
                displayName: AccessInput::optionalText($request, 'display_name'),
                userId: AccessInput::optionalText($request, 'user_id'),
                status: AccessInput::optionalText($request, 'status'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(AccessViews::employee($view), 201);
    }
}
