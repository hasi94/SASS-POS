<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use App\Http\Responses\AccessViews;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\ListEmployees\ListEmployees;
use Flexipos\Access\Features\ListEmployees\ListEmployeesHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/access/employees` - one page of live employees (AC-29).
 *
 * `page_size` is the one value this task reads from a URL, and it is the
 * right place for it: a page size is not a credential, and an over-large one
 * is clamped rather than refused so a caller gets the data instead of an
 * error. Nothing in the rendered rows is a secret - {@see AccessViews} works
 * from a view type that has no `pin_hash` and no `perm_version` (AC-65).
 */
final class ListEmployeesController extends Controller
{
    public function __construct(private readonly ListEmployeesHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $views = $this->handler->handle(new ListEmployees(AccessInput::pageSize($request)));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse(['employees' => AccessViews::employees($views)], 200);
    }
}
