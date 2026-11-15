<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Responses\AccessViews;
use Flexipos\Access\Features\GetMyPermissions\GetMyPermissions;
use Flexipos\Access\Features\GetMyPermissions\GetMyPermissionsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/access/me/permissions` - what the caller may do, live
 * (AC-60).
 *
 * **The one `authenticated_only` route in this task** (AC-63). It needs
 * no permission key because there is no subject but the caller: the
 * employee comes from the verified token's `employee` claim and there is
 * no field, path parameter or query string through which another could
 * arrive. Guarding it with `employees.view` would mean a cashier could
 * not find out what they themselves may do, which is the opposite of
 * least privilege rather than an application of it.
 *
 * A token with no `employee` claim reaches it and is answered with an
 * empty set (AC-60's last clause) - the only route such a token reaches
 * at all, since every guarded one refuses it `not_permitted`.
 */
final class GetMyPermissionsController extends Controller
{
    public function __construct(private readonly GetMyPermissionsHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        // The same reader the guard uses, so "who is asking" has one
        // answer on this route and on every other.
        $employeeId = RequireTenantPermission::employeeId($request);

        return new JsonResponse(
            AccessViews::myPermissions($this->handler->handle(new GetMyPermissions($employeeId))),
            200
        );
    }
}
