<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\UpdateBranch\UpdateBranch;
use Flexipos\Organization\Features\UpdateBranch\UpdateBranchHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PATCH /api/v1/organization/branches/{branch}` - the editable fields (AC-12).
 */
final class UpdateBranchController extends Controller
{
    public function __construct(private readonly UpdateBranchHandler $handler) {}

    public function __invoke(Request $request, string $branch): JsonResponse
    {
        try {
            $this->handler->handle(new UpdateBranch(
                $branch,
                AccessInput::fields($request),
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse(['branch_id' => strtoupper($branch), 'updated' => true], 200);
    }
}
