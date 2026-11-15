<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\OrganizationRefusals;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\CreateBranch\CreateBranch;
use Flexipos\Organization\Features\CreateBranch\CreateBranchHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/organization/branches` - a branch and its store location (AC-10).
 *
 * The body is read whole and handed to the slice, which refuses the fields the
 * operation does not take. The response carries the two ids and the folded code.
 */
final class CreateBranchController extends Controller
{
    public function __construct(private readonly CreateBranchHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $result = $this->handler->handle(new CreateBranch(
                AccessInput::fields($request),
                RequireTenantPermission::employeeId($request),
            ));
        } catch (OrganizationRefused $refused) {
            return OrganizationRefusals::refused($refused);
        }

        return new JsonResponse([
            'branch_id' => $result->branchId,
            'location_id' => $result->locationId,
            'code' => $result->code,
        ], 201);
    }
}
