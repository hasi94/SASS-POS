<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCode;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCodeHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/organization/terminals/{terminal}/activation-code` - a new code for a pending terminal, shown once (AC-28).
 */
final class ReissueActivationCodeController extends Controller
{
    public function __construct(private readonly ReissueActivationCodeHandler $handler) {}

    public function __invoke(Request $request, string $terminal): JsonResponse
    {
        try {
            $result = $this->handler->handle(new ReissueActivationCode(
                $terminal,
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse([
            'terminal_id' => $result->terminalId,
            'activation_code' => $result->activationCode,
            'activation_expires_at' => $result->activationExpiresAt,
        ], 200);
    }
}
