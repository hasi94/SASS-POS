<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccessInput;
use Flexipos\Organization\Features\ListBranches\ListBranches;
use Flexipos\Organization\Features\ListBranches\ListBranchesHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/organization/branches` - the branches, one page (AC-13).
 */
final class ListBranchesController extends Controller
{
    public function __construct(private readonly ListBranchesHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(
            ['branches' => $this->handler->handle(new ListBranches(AccessInput::pageSize($request)))],
            200,
        );
    }
}
