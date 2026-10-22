<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ChangeTerminalStatus\ChangeTerminalStatus;
use Flexipos\Organization\Features\ChangeTerminalStatus\ChangeTerminalStatusHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT /api/v1/organization/terminals/{terminal}/status` - body `{"status": "inactive"|"active"}` (AC-37).
 */
final class ChangeTerminalStatusController extends Controller
{
    public function __construct(private readonly ChangeTerminalStatusHandler $handler) {}

    public function __invoke(Request $request, string $terminal): JsonResponse
    {
        try {
            $status = AccessInput::raw($request, 'status');

            $this->handler->handle(new ChangeTerminalStatus(
                $terminal,
                $status,
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['terminal_id' => strtoupper($terminal), 'status' => $status], 200);
    }
}
