<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\GetLocation\GetLocation;
use Flexipos\Organization\Features\GetLocation\GetLocationHandler;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/organization/locations/{location}` - one location (AC-25).
 */
final class GetLocationController extends Controller
{
    public function __construct(private readonly GetLocationHandler $handler) {}

    public function __invoke(string $location): JsonResponse
    {
        try {
            $view = $this->handler->handle(new GetLocation($location));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse($view, 200);
    }
}
