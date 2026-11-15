<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ListLocations\ListLocations;
use Flexipos\Organization\Features\ListLocations\ListLocationsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/organization/locations?branch_id=&type=` - the locations, one page (AC-25).
 *
 * The two filters are the one thing here read from the query string, because
 * a filter is not a credential and belongs in a GET's URL.
 */
final class ListLocationsController extends Controller
{
    public function __construct(private readonly ListLocationsHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $locations = $this->handler->handle(new ListLocations(
                AccessInput::pageSize($request),
                $request->query('branch_id'),
                $request->query('type'),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['locations' => $locations], 200);
    }
}
