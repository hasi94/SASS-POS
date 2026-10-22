<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ChangeLocationStatus\ChangeLocationStatus;
use Flexipos\Organization\Features\ChangeLocationStatus\ChangeLocationStatusHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT /api/v1/organization/locations/{location}/status` - body `{"is_active": bool}` (AC-24).
 */
final class ChangeLocationStatusController extends Controller
{
    public function __construct(private readonly ChangeLocationStatusHandler $handler) {}

    public function __invoke(Request $request, string $location): JsonResponse
    {
        $isActive = AccessInput::raw($request, 'is_active');

        try {
            $this->handler->handle(new ChangeLocationStatus(
                $location,
                $isActive,
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['location_id' => strtoupper($location), 'is_active' => $isActive], 200);
    }
}
