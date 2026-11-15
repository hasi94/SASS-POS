<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Illuminate\Database\Connection;

/**
 * Where a terminal may sell from (T-014 D-30, AC-26, AC-42).
 *
 * **One rule, asked by creating a terminal and by changing its location**: the
 * location must exist, be active, belong to the terminal's own branch and be
 * sellable from. A central warehouse (no branch) cannot be a selling
 * location, and neither can another branch's. Each failing property is
 * `validation`, so the caller corrects the submission; the branch itself is
 * checked separately, because its refusals (`unknown_branch`,
 * `branch_inactive`) are different ones.
 */
final class TerminalPlacement
{
    /**
     * @throws OrganizationRefused validation
     */
    public static function requireSellingLocation(Connection $connection, mixed $locationId, string $branchId): object
    {
        $id = Identifier::fold($locationId, 'location_id');

        $location = $connection->table('locations')->where('id', $id)->whereNull('deleted_at')->first();

        if ($location === null) {
            throw OrganizationRefused::validation("No location has id [{$id}].");
        }

        if (! (bool) $location->is_active) {
            throw OrganizationRefused::validation("Location [{$id}] is not active.");
        }

        if ($location->branch_id === null || (string) $location->branch_id !== $branchId) {
            throw OrganizationRefused::validation("Location [{$id}] does not belong to the terminal's branch.");
        }

        if (! (bool) $location->is_sellable_from) {
            throw OrganizationRefused::validation("Location [{$id}] cannot be sold from.");
        }

        return $location;
    }
}
