<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ListTerminals\ListTerminals;
use Flexipos\Organization\Features\ListTerminals\ListTerminalsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/organization/terminals?branch_id=&status=` - the terminals, one page, never a hash (AC-42).
 *
 * The two filters are the one thing here read from the query string: a filter
 * is not a credential and belongs in a GET's URL.
 */
final class ListTerminalsController extends Controller
{
    public function __construct(private readonly ListTerminalsHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $terminals = $this->handler->handle(new ListTerminals(
                AccessInput::pageSize($request),
                $request->query('branch_id'),
                $request->query('status'),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['terminals' => $terminals], 200);
    }
}
