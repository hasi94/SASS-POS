<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ListEffectiveSettings\ListEffectiveSettings;
use Flexipos\Organization\Features\ListEffectiveSettings\ListEffectiveSettingsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/organization/settings?scope_type=&scope_id=` - the effective
 * settings for a scope, with the layer each came from (AC-48).
 *
 * The scope is in the query string because a read has no body and a scope is
 * an identifier, not a credential; a secret's value is not in the answer at
 * all, so there is nothing here a URL could leak.
 */
final class ListSettingsController extends Controller
{
    public function __construct(private readonly ListEffectiveSettingsHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $settings = $this->handler->handle(new ListEffectiveSettings(
                $request->query('scope_type'),
                $request->query('scope_id'),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['settings' => $settings], 200);
    }
}
