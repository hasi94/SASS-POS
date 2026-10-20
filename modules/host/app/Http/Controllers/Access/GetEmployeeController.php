<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Responses\AccessRefusals;
use App\Http\Responses\AccessViews;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\GetEmployee\GetEmployee;
use Flexipos\Access\Features\GetEmployee\GetEmployeeHandler;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/access/employees/{employee}` - one live employee (AC-29).
 *
 * The response carries `has_pin` and never the digest, the counter or the
 * lock (AC-48, AC-65), because the view type it renders holds none of them.
 */
final class GetEmployeeController extends Controller
{
    public function __construct(private readonly GetEmployeeHandler $handler) {}

    public function __invoke(string $employee): JsonResponse
    {
        try {
            $view = $this->handler->handle(new GetEmployee($employee));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(AccessViews::employee($view), 200);
    }
}
