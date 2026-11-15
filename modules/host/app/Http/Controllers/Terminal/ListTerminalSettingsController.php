<?php

declare(strict_types=1);

namespace App\Http\Controllers\Terminal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTerminalToken;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ListTerminalSettings\ListTerminalSettings;
use Flexipos\Organization\Features\ListTerminalSettings\ListTerminalSettingsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/terminal/settings` - a terminal's own effective settings
 * (AC-50).
 *
 * Behind `RequireTerminalToken` alone: the terminal id is the verified
 * token's, never a path or query value, so a terminal cannot ask about
 * another. No employee permission applies to this route and none is
 * consulted.
 */
final class ListTerminalSettingsController extends Controller
{
    public function __construct(private readonly ListTerminalSettingsHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $settings = $this->handler->handle(new ListTerminalSettings((string) $request->attributes->get(RequireTerminalToken::TERMINAL_ID)));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['settings' => $settings], 200);
    }
}
