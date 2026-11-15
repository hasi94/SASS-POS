<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\SetSetting\SetSetting;
use Flexipos\Organization\Features\SetSetting\SetSettingHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT /api/v1/organization/settings` - body `{key, scope_type, scope_id, value}`
 * (AC-45, AC-47).
 *
 * **The value is read from the body only, and a `value` in the query string
 * is refused outright** rather than ignored: a secret setting's value in a
 * URL is written to an access log on the way in, and a client that sent it
 * there needs to be told, not silently half-served (AC-47). Nothing in the
 * response is the value.
 */
final class SetSettingController extends Controller
{
    public function __construct(private readonly SetSettingHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        if ($request->query->has('value')) {
            return OrganizationRefusals::of(OrganizationRefusal::Validation);
        }

        try {
            $result = $this->handler->handle(new SetSetting(
                AccessInput::fields($request),
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse($result, 200);
    }
}
