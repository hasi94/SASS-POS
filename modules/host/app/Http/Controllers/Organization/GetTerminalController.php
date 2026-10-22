<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\GetTerminal\GetTerminal;
use Flexipos\Organization\Features\GetTerminal\GetTerminalHandler;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/organization/terminals/{terminal}` - one terminal, never its hash (AC-42).
 */
final class GetTerminalController extends Controller
{
    public function __construct(private readonly GetTerminalHandler $handler) {}

    public function __invoke(string $terminal): JsonResponse
    {
        try {
            $view = $this->handler->handle(new GetTerminal($terminal));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse($view, 200);
    }
}
