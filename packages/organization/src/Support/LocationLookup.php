<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Illuminate\Database\Connection;

/**
 * Finding one live location, and what a location looks like on the wire
 * (T-014 AC-25).
 *
 * Soft-deleted rows are excluded, a missing row is `not_found`, the id is
 * folded first (AC-61), and no query carries a tenant predicate: the database
 * is the boundary.
 */
final class LocationLookup
{
    /** The closed set of location types (D-11); only two are creatable here. */
    public const TYPES = ['branch_store', 'warehouse', 'transit', 'consignment', 'damaged', 'virtual'];

    /**
     * @throws OrganizationRefused validation (malformed id), not_found
     */
    public static function find(Connection $connection, mixed $locationId, bool $lock = false): object
    {
        $id = Identifier::fold($locationId, 'location id');

        $query = $connection->table('locations')->where('id', $id)->whereNull('deleted_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row === null) {
            throw OrganizationRefused::notFound("No location has id [{$id}].");
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
            'branch_id' => $row->branch_id === null ? null : (string) $row->branch_id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'type' => (string) $row->type,
            'address' => $row->address === null ? null : json_decode((string) $row->address, true),
            'manager_employee_id' => $row->manager_employee_id === null ? null : (string) $row->manager_employee_id,
            'allow_negative_stock' => (bool) $row->allow_negative_stock,
            'is_sellable_from' => (bool) $row->is_sellable_from,
            'is_purchasable_to' => (bool) $row->is_purchasable_to,
            'is_active' => (bool) $row->is_active,
            'sort_order' => (int) $row->sort_order,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
            'created_by' => $row->created_by === null ? null : (string) $row->created_by,
            'updated_by' => $row->updated_by === null ? null : (string) $row->updated_by,
        ];
    }

    /**
     * What an audit row records for a location: what a person can change.
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
