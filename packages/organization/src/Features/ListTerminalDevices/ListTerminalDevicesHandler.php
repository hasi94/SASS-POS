<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListTerminalDevices;

use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalLookup;

/**
 * A terminal's devices, oldest first (T-014 AC-41, D-16).
 *
 * Read-only by design: no route modifies or deletes a device, and a device is
 * revoked only as part of revoking its terminal. **Each item shows the
 * fingerprint and the descriptive fields and never an activation hash or
 * code** - the device table holds neither, and the list names its columns
 * rather than selecting `*`, so a column added to `devices` later is not
 * published by accident. A terminal that does not exist is `not_found`.
 */
final class ListTerminalDevicesHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListTerminalDevices $query): array
    {
        $connection = $this->tenant->get();
        $terminal = TerminalLookup::find($connection, $query->terminalId);

        $devices = [];

        $rows = $connection->table('devices')
            ->where('terminal_id', (string) $terminal->id)
            ->orderBy('registered_at')
            ->orderBy('id')
            ->get(['id', 'fingerprint', 'platform', 'browser', 'os', 'screen', 'registered_at', 'last_seen_at', 'revoked_at', 'revoked_by']);

        foreach ($rows as $row) {
            $devices[] = [
                'id' => (string) $row->id,
                'fingerprint' => (string) $row->fingerprint,
                'platform' => $row->platform === null ? null : (string) $row->platform,
                'browser' => $row->browser === null ? null : (string) $row->browser,
                'os' => $row->os === null ? null : (string) $row->os,
                'screen' => $row->screen === null ? null : (string) $row->screen,
                'registered_at' => (string) $row->registered_at,
                'last_seen_at' => $row->last_seen_at === null ? null : (string) $row->last_seen_at,
                'revoked_at' => $row->revoked_at === null ? null : (string) $row->revoked_at,
                'revoked_by' => $row->revoked_by === null ? null : (string) $row->revoked_by,
            ];
        }

        return $devices;
    }
}
