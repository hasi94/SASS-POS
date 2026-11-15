<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Illuminate\Database\Connection;

/**
 * Finding one live terminal, and what a terminal looks like on the wire
 * (T-014 AC-42).
 *
 * **The view carries `has_pending_code` and never the hash.** An activation
 * hash is a credential in a database dump, so it has no key in any array this
 * class builds, and a test asserts that by enumerating the keys. Soft-deleted
 * rows are excluded and a missing row is `not_found`.
 */
final class TerminalLookup
{
    /**
     * @throws OrganizationRefused validation (malformed id), not_found
     */
    public static function find(Connection $connection, mixed $terminalId, bool $lock = false): object
    {
        $id = Identifier::fold($terminalId, 'terminal id');

        $query = $connection->table('terminals')->where('id', $id)->whereNull('deleted_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row === null) {
            throw OrganizationRefused::notFound("No terminal has id [{$id}].");
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
            'branch_id' => (string) $row->branch_id,
            'location_id' => (string) $row->location_id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'status' => (string) $row->status,
            'last_seen_at' => $row->last_seen_at === null ? null : (string) $row->last_seen_at,
            'current_device_id' => $row->current_device_id === null ? null : (string) $row->current_device_id,
            'has_pending_code' => $row->activation_code_hash !== null,
            'activation_expires_at' => $row->activation_expires_at === null ? null : (string) $row->activation_expires_at,
        ];
    }

    /**
     * What an audit row records for a terminal. The same fields as the view,
     * so the hash is as absent from the audit table as from the wire.
     *
     * @return array<string, mixed>
     */
    public static function audited(object $row): array
    {
        return self::view($row);
    }
}
