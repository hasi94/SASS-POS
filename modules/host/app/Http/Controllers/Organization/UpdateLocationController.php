<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\UpdateLocation\UpdateLocation;
use Flexipos\Organization\Features\UpdateLocation\UpdateLocationHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PATCH /api/v1/organization/locations/{location}` - the editable fields (AC-23).
 */
final class UpdateLocationController extends Controller
{
    public function __construct(private readonly UpdateLocationHandler $handler) {}

    public function __invoke(Request $request, string $location): JsonResponse
    {
        try {
            $this->handler->handle(new UpdateLocation(
                $location,
                AccessInput::fields($request),
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['location_id' => strtoupper($location), 'updated' => true], 200);
    }
}
