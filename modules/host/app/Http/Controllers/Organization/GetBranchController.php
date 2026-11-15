<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\GetBranch\GetBranch;
use Flexipos\Organization\Features\GetBranch\GetBranchHandler;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/organization/branches/{branch}` - one branch (AC-13).
 */
final class GetBranchController extends Controller
{
    public function __construct(private readonly GetBranchHandler $handler) {}

    public function __invoke(string $branch): JsonResponse
    {
        try {
            $view = $this->handler->handle(new GetBranch($branch));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse($view, 200);
    }
}
