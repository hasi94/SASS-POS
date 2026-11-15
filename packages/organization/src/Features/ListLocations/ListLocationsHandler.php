<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListLocations;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Support\Identifier;
use Flexipos\Organization\Support\LocationLookup;
use Flexipos\Organization\Support\PageSize;
use Flexipos\Organization\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The locations ordered by `code`, inactive ones included with their flag,
 * soft-deleted ones excluded, one page, filterable by branch and by type
 * (T-014 AC-25).
 *
 * **Filtering by a branch excludes the null-branch warehouses**: a central
 * warehouse belongs to no branch, so it is in no branch's list. An unknown
 * type filter is `validation`, not an empty list, because an empty list would
 * read as "there are none".
 */
final class ListLocationsHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListLocations $query): array
    {
        $builder = $this->tenant->get()->table('locations')->whereNull('deleted_at');

        if ($query->branchId !== null) {
            $builder->where('branch_id', Identifier::fold($query->branchId, 'branch_id filter'));
        }

        if ($query->type !== null) {
            if (! is_string($query->type) || ! in_array($query->type, LocationLookup::TYPES, true)) {
                throw OrganizationRefused::validation('The type filter is not a location type.');
            }

            $builder->where('type', $query->type);
        }

        $views = [];

        foreach ($builder->orderBy('code')->limit(PageSize::resolve($query->pageSize, $this->config))->get() as $row) {
            $views[] = LocationLookup::view($row);
        }

        return $views;
    }
}
