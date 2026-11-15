<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\UpdateTerminal\UpdateTerminal;
use Flexipos\Organization\Features\UpdateTerminal\UpdateTerminalHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PATCH /api/v1/organization/terminals/{terminal}` - name or selling location (AC-42).
 */
final class UpdateTerminalController extends Controller
{
    public function __construct(private readonly UpdateTerminalHandler $handler) {}

    public function __invoke(Request $request, string $terminal): JsonResponse
    {
        try {
            $this->handler->handle(new UpdateTerminal(
                $terminal,
                AccessInput::fields($request),
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['terminal_id' => strtoupper($terminal), 'updated' => true], 200);
    }
}
