<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use App\Http\Responses\AccessViews;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\CreateRole\CreateRole;
use Flexipos\Access\Features\CreateRole\CreateRoleHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/access/roles` - a new custom role (AC-19).
 *
 * `key` and `name` are read from the body and judged by the slice; the
 * controller refuses only what it cannot pass on - a field submitted with
 * the wrong type, which would otherwise reach a `string` parameter and be a
 * 500 instead of the 422 AC-19 asks for.
 */
final class CreateRoleController extends Controller
{
    public function __construct(private readonly CreateRoleHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $view = $this->handler->handle(new CreateRole(
                key: AccessInput::text($request, 'key'),
                name: AccessInput::text($request, 'name'),
                description: AccessInput::optionalText($request, 'description'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(AccessViews::role($view), 201);
    }
}
