<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\DeleteRole\DeleteRole;
use Flexipos\Access\Features\DeleteRole\DeleteRoleHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE /api/v1/access/roles/{role}` - a custom role nobody holds (AC-22).
 *
 * Soft deletion, and the key stays reserved (AC-19): a role still assigned
 * to anybody is refused `role_in_use`, which is a different remedy from the
 * `system_role` and `validation` refusals the same route can give.
 */
final class DeleteRoleController extends Controller
{
    public function __construct(private readonly DeleteRoleHandler $handler) {}

    public function __invoke(Request $request, string $role): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $this->handler->handle(new DeleteRole($role, $actorId));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(['role_id' => $role, 'deleted' => true], 200);
    }
}
