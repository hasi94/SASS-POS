<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Illuminate\Database\Connection;

/**
 * Finding one live branch, and what a branch looks like on the wire
 * (T-014 AC-13).
 *
 * **Soft-deleted rows are excluded and a missing row is `not_found`.** The
 * id is folded first (AC-61), so a lower-case spelling resolves to the same
 * branch. There is no tenant predicate anywhere: the database is the
 * boundary, so another tenant's well-formed id simply is not here.
 */
final class BranchLookup
{
    /**
     * @throws OrganizationRefused validation (malformed id), not_found
     */
    public static function find(Connection $connection, mixed $branchId, bool $lock = false): object
    {
        $id = Identifier::fold($branchId, 'branch id');

        $query = $connection->table('branches')->where('id', $id)->whereNull('deleted_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row === null) {
            throw OrganizationRefused::notFound("No branch has id [{$id}].");
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function view(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'address' => $row->address === null ? null : json_decode((string) $row->address, true),
            'phone' => $row->phone === null ? null : (string) $row->phone,
            'email' => $row->email === null ? null : (string) $row->email,
            'timezone' => (string) $row->timezone,
            'currency' => (string) $row->currency,
            'tax_registration_no' => $row->tax_registration_no === null ? null : (string) $row->tax_registration_no,
            'business_type' => $row->business_type === null ? null : (string) $row->business_type,
            'opening_date' => $row->opening_date === null ? null : (string) $row->opening_date,
            'default_location_id' => $row->default_location_id === null ? null : (string) $row->default_location_id,
            'is_active' => (bool) $row->is_active,
            'sort_order' => (int) $row->sort_order,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
            'created_by' => $row->created_by === null ? null : (string) $row->created_by,
            'updated_by' => $row->updated_by === null ? null : (string) $row->updated_by,
        ];
    }

    /**
     * The fields an audit row records for a branch: everything a person can
     * change, and never a secret (a branch has none).
     *
     * @return array<string, mixed>
     */
    public static function audited(object $row): array
    {
        $view = self::view($row);

        unset($view['created_at'], $view['updated_at'], $view['created_by'], $view['updated_by']);

        return $view;
    }
}
