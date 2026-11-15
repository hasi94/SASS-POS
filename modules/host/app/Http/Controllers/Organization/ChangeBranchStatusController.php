<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ChangeBranchStatus\ChangeBranchStatus;
use Flexipos\Organization\Features\ChangeBranchStatus\ChangeBranchStatusHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT /api/v1/organization/branches/{branch}/status` - body `{"is_active": bool}` (AC-14).
 */
final class ChangeBranchStatusController extends Controller
{
    public function __construct(private readonly ChangeBranchStatusHandler $handler) {}

    public function __invoke(Request $request, string $branch): JsonResponse
    {
        $isActive = AccessInput::raw($request, 'is_active');

        try {
            $this->handler->handle(new ChangeBranchStatus(
                $branch,
                $isActive,
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['branch_id' => strtoupper($branch), 'is_active' => $isActive], 200);
    }
}
