<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissions;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissionsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT /api/v1/access/roles/{role}/permissions` - a role's permission set, replaced whole (AC-20).
 *
 * Replace, never merge, and all or nothing: one unregistered key refuses the
 * whole submission. The bump count is reported because AC-20 is about every
 * holder of the role being bumped, and a caller that had to compare versions
 * to learn it would be re-deriving the handler's own work.
 */
final class SetRolePermissionsController extends Controller
{
    public function __construct(private readonly SetRolePermissionsHandler $handler) {}

    public function __invoke(Request $request, string $role): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $result = $this->handler->handle(new SetRolePermissions(
                roleId: $role,
                permissionKeys: AccessInput::textList($request, 'permission_keys'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse([
            'role_id' => $result->roleId,
            'permission_keys' => $result->permissionKeys,
            'bumped' => $result->bumped,
        ], 200);
    }
}
