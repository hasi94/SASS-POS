<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * {@see TerminalAuthority} over the tenant database (T-014 AC-34, AC-36,
 * D-19, D-35).
 *
 * **Every question is a fresh read of the terminal row and its credential
 * row**, never a cache and never a claim: "revoked" has to mean the next
 * request, and a memo of this answer would make it mean "when the memo
 * expires". There is no tenant predicate: the database is the boundary.
 *
 * `activeBranchOf()` is non-null only when the terminal exists, is not
 * soft-deleted, is `active`, and its credential is `active` - all four, so the
 * credential's own revocation refuses a terminal whose row still says active
 * (the state a half-finished revoke would leave). `isPaused()` is the one
 * extra fact a refresh needs to tell a pause from a revocation (D-35).
 */
final class DatabaseTerminalAuthority implements TerminalAuthority
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
    ) {}

    public function activeBranchOf(string $terminalId): ?string
    {
        $branch = $this->tenant->get()->table('terminals')
            ->join('terminal_credentials', 'terminal_credentials.terminal_id', '=', 'terminals.id')
            ->where('terminals.id', $terminalId)
            ->whereNull('terminals.deleted_at')
            ->where('terminals.status', TerminalStatus::ACTIVE)
            ->where('terminal_credentials.status', 'active')
            ->value('terminals.branch_id');

        return $branch === null ? null : (string) $branch;
    }

    public function isPaused(string $terminalId): bool
    {
        return $this->tenant->get()->table('terminals')
            ->join('terminal_credentials', 'terminal_credentials.terminal_id', '=', 'terminals.id')
            ->where('terminals.id', $terminalId)
            ->whereNull('terminals.deleted_at')
            ->where('terminals.status', TerminalStatus::INACTIVE)
            ->where('terminal_credentials.status', 'active')
            ->exists();
    }

    public function recordSeen(string $terminalId): void
    {
        $connection = $this->tenant->get();
        $now = $this->clock->timestamp();

        $connection->transaction(function () use ($connection, $terminalId, $now): void {
            $deviceId = $connection->table('terminals')->where('id', $terminalId)->value('current_device_id');

            $connection->table('terminals')->where('id', $terminalId)->update(['last_seen_at' => $now]);

            if ($deviceId !== null) {
                $connection->table('devices')->where('id', (string) $deviceId)->update(['last_seen_at' => $now]);
            }
        });
    }
}
