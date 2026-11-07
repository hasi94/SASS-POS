<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Contracts\Organization\BranchSeed;
use Flexipos\Contracts\Organization\DefaultBranch;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\CreateBranch\CreateBranch;
use Flexipos\Organization\Features\CreateBranch\CreateBranchHandler;

/**
 * {@see DefaultBranch}, for a tenant's one default branch (T-026 Ruling 5,
 * AC-1, AC-26, B-12).
 *
 * **`validate()` reuses `CreateBranch`'s own field rules rather than
 * restating them**, through the same {@see Fields} and {@see OrgCode}
 * helpers `CreateBranchHandler` itself calls - so a tenant that passes AC-1's
 * check is guaranteed to pass `CreateBranch`'s own validation later, with no
 * second rule to go out of step with the first.
 *
 * **`ensure()` is idempotent by reading first.** A resumed `seed_core` must
 * not call `CreateBranch` a second time for a code that already exists in
 * this tenant - `CreateBranch` itself would refuse that as "already in use"
 * - so this class checks `branches` for the code before ever reaching the
 * handler.
 */
final class DatabaseDefaultBranch implements DefaultBranch
{
    public function __construct(
        private readonly CreateBranchHandler $createBranch,
        private readonly TenantConnection $tenant,
    ) {}

    public function validate(BranchSeed $seed): void
    {
        OrgCode::normalise($seed->resolvedCode(), 'branch code');

        Fields::requiredText(['name' => $seed->resolvedName()], 'name', 100);
        Fields::timezone(['timezone' => $seed->timezone]);
        Fields::currency(['currency' => $seed->currency]);

        if (! in_array($seed->businessType, Fields::BUSINESS_TYPES, true)) {
            throw OrganizationRefused::validation(
                'The business_type must be one of '.implode(', ', Fields::BUSINESS_TYPES).'.'
            );
        }
    }

    public function ensure(BranchSeed $seed): string
    {
        $code = OrgCode::normalise($seed->resolvedCode(), 'branch code');

        $connection = $this->tenant->get();

        $existing = $connection->table('branches')
            ->where('code', $code)
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null) {
            /** @var object{id: string} $existing */
            return (string) $existing->id;
        }

        $result = $this->createBranch->handle(new CreateBranch([
            'code' => $seed->resolvedCode(),
            'name' => $seed->resolvedName(),
            'timezone' => $seed->timezone,
            'currency' => $seed->currency,
            'business_type' => $seed->businessType,
        ]));

        return $result->branchId;
    }

    public function exists(string $branchId): bool
    {
        return $this->tenant->get()->table('branches')
            ->where('id', $branchId)
            ->whereNull('deleted_at')
            ->exists();
    }
}
