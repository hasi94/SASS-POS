<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\CreateWarehouse\CreateWarehouse;
use Flexipos\Organization\Features\CreateWarehouse\CreateWarehouseHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/organization/locations` - a warehouse, and only a warehouse (AC-21, AC-22).
 */
final class CreateWarehouseController extends Controller
{
    public function __construct(private readonly CreateWarehouseHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $result = $this->handler->handle(new CreateWarehouse(
                AccessInput::fields($request),
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse($result, 201);
    }
}
