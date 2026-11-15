<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\GetLocation;

use Flexipos\Organization\Support\LocationLookup;
use Flexipos\Organization\Support\TenantConnection;

/**
 * One location (T-014 AC-25): a missing id, a soft-deleted row and another
 * tenant's well-formed id are the same `not_found`.
 */
final class GetLocationHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetLocation $query): array
    {
        return LocationLookup::view(LocationLookup::find($this->tenant->get(), $query->locationId));
    }
}
