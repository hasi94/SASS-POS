<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\RevokeTerminal\RevokeTerminal;
use Flexipos\Organization\Features\RevokeTerminal\RevokeTerminalHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/organization/terminals/{terminal}/revoke` - a terminal revoked,
 * and whether its sessions were too (AC-38, AC-39, D-43).
 *
 * 200 with `sessions_revoked: true` on full success and `false` when the
 * tenant side committed and the master step did not: the tenant state did
 * change, so a 2xx with an explicit field is the accurate answer.
 */
final class RevokeTerminalController extends Controller
{
    public function __construct(private readonly RevokeTerminalHandler $handler) {}

    public function __invoke(Request $request, string $terminal): JsonResponse
    {
        try {
            $result = $this->handler->handle(new RevokeTerminal($terminal, RequireTenantPermission::employeeId($request)));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse($result->toResponse(), 200);
    }
}
