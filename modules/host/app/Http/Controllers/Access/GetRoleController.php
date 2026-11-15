<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Responses\AccessRefusals;
use App\Http\Responses\AccessViews;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\GetRole\GetRole;
use Flexipos\Access\Features\GetRole\GetRoleHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/access/roles/{role}` - one live role (AC-23).
 *
 * A soft-deleted role is refused `validation` by the handler rather than
 * returned with a flag: the closed refusal set has no `not_found`, and the
 * request did name something that is not there.
 */
final class GetRoleController extends Controller
{
    public function __construct(private readonly GetRoleHandler $handler) {}

    public function __invoke(string $role): JsonResponse
    {
        try {
            $view = $this->handler->handle(new GetRole($role));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(AccessViews::role($view), 200);
    }
}
