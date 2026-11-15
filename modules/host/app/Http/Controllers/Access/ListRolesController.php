<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Responses\AccessRefusals;
use App\Http\Responses\AccessViews;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\ListRoles\ListRoles;
use Flexipos\Access\Features\ListRoles\ListRolesHandler;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/access/roles` - every live role, with its permission keys (AC-23).
 *
 * Ordered by `key` and excluding soft-deleted roles - both the handler's,
 * not this class's. A controller that re-sorted would be a second answer to
 * "what does the role list look like".
 */
final class ListRolesController extends Controller
{
    public function __construct(private readonly ListRolesHandler $handler) {}

    public function __invoke(): JsonResponse
    {
        try {
            $roles = $this->handler->handle(new ListRoles);
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(['roles' => AccessViews::roles($roles)], 200);
    }
}
