<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use App\Http\Responses\AccessViews;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\RenameRole\RenameRole;
use Flexipos\Access\Features\RenameRole\RenameRoleHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PATCH /api/v1/access/roles/{role}` - a custom role's name and description (AC-21).
 *
 * A system role is refused `system_role` by `RoleLookup::requireCustom()`,
 * which is the one place `roles.is_system` is read.
 */
final class RenameRoleController extends Controller
{
    public function __construct(private readonly RenameRoleHandler $handler) {}

    public function __invoke(Request $request, string $role): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $view = $this->handler->handle(new RenameRole(
                roleId: $role,
                name: AccessInput::text($request, 'name'),
                description: AccessInput::optionalText($request, 'description'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(AccessViews::role($view), 200);
    }
}
