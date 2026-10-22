<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ListTerminalDevices\ListTerminalDevices;
use Flexipos\Organization\Features\ListTerminalDevices\ListTerminalDevicesHandler;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/organization/terminals/{terminal}/devices` - the terminal's devices, oldest first (AC-41).
 */
final class ListTerminalDevicesController extends Controller
{
    public function __construct(private readonly ListTerminalDevicesHandler $handler) {}

    public function __invoke(string $terminal): JsonResponse
    {
        try {
            $devices = $this->handler->handle(new ListTerminalDevices($terminal));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['devices' => $devices], 200);
    }
}
