<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ClearSetting\ClearSetting;
use Flexipos\Organization\Features\ClearSetting\ClearSettingHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/organization/settings/clear` - body `{key, scope_type, scope_id}`
 * (AC-46, clearing half).
 */
final class ClearSettingController extends Controller
{
    public function __construct(private readonly ClearSettingHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $result = $this->handler->handle(new ClearSetting(
                AccessInput::fields($request),
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse($result, 200);
    }
}
