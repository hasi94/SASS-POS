<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminal;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminalHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/organization/terminals` - a terminal and its activation code, shown once (AC-26).
 */
final class CreateTerminalController extends Controller
{
    public function __construct(private readonly CreateTerminalHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $result = $this->handler->handle(new CreateTerminal(
                AccessInput::fields($request),
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse([
            'terminal_id' => $result->terminalId,
            'activation_code' => $result->activationCode,
            'activation_expires_at' => $result->activationExpiresAt,
        ], 201);
    }
}
