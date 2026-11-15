<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\RevokeTerminal;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalLookup;
use Flexipos\Organization\Support\TerminalStatus;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revoke a terminal: the tenant first, then the master (T-014 AC-38, AC-39,
 * AC-40, D-15, D-21, Ruling 11).
 *
 * **Two databases, no distributed transaction, and the order is what makes
 * that safe.**
 *
 * 1. One tenant transaction, the row locked `for update`: the terminal is
 *    `revoked`, its credential (if it ever had one) is `revoked` with the
 *    instant and the actor, every device not already revoked is revoked, the
 *    activation hash and expiry are cleared, and **one** audit row is
 *    written. From this commit on the next request is refused (AC-34), which
 *    is why the tenant side is authoritative.
 * 2. Only then, outside that transaction, the master: every refresh family of
 *    this terminal in this tenant is revoked through
 *    {@see TerminalSessions::revokeAll()}.
 *
 * **If the master step fails, the terminal stays revoked and the answer says
 * so**: `sessions_revoked` is false, the failure is logged by class and never
 * by message, and **repeating the revoke is a success that retries the master
 * step** and writes no second audit row, because a repeat of a completed
 * revoke changes nothing on the tenant side. The master step is idempotent, so
 * the retry is safe at any point.
 *
 * **Revoked is final** (D-15): no operation moves a revoked terminal back, and
 * revoking one again is the no-change path above, not an error.
 */
final class RevokeTerminalHandler
{
    public const ACTION = 'terminal_revoked';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly TerminalSessions $sessions,
        private readonly Tenancy $tenancy,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(RevokeTerminal $command): RevokeTerminalResult
    {
        $connection = $this->tenant->get();

        $terminalId = $connection->transaction(function () use ($connection, $command): string {
            $terminal = TerminalLookup::find($connection, $command->terminalId, lock: true);
            $id = (string) $terminal->id;

            // Already revoked: no change and no audit row; the master step
            // below is the only thing a repeat does.
            if ((string) $terminal->status === TerminalStatus::REVOKED) {
                return $id;
            }

            $now = $this->clock->timestamp();

            $connection->table('terminals')->where('id', $id)->update([
                'status' => TerminalStatus::REVOKED,
                'activation_code_hash' => null,
                'activation_expires_at' => null,
                'updated_at' => $now,
            ]);

            $connection->table('terminal_credentials')
                ->where('terminal_id', $id)
                ->where('status', '!=', 'revoked')
                ->update(['status' => 'revoked', 'revoked_at' => $now, 'revoked_by' => $command->actorId]);

            $connection->table('devices')
                ->where('terminal_id', $id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'revoked_by' => $command->actorId, 'updated_at' => $now]);

            $after = $connection->table('terminals')->where('id', $id)->first();

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                self::ACTION,
                'terminal',
                $id,
                TerminalLookup::audited($terminal),
                $after === null ? null : TerminalLookup::audited($after),
                (string) $terminal->branch_id,
            );

            return $id;
        });

        return new RevokeTerminalResult($terminalId, $this->revokeSessions($terminalId));
    }

    /**
     * The master step. A failure is caught here, logged by class, and
     * reported as `false`: it must not undo or hide the revocation that has
     * already committed.
     */
    private function revokeSessions(string $terminalId): bool
    {
        $tenantId = $this->tenancy->current()->tenantId();

        try {
            $this->sessions->revokeAll($tenantId, $terminalId);

            return true;
        } catch (Throwable $failure) {
            $this->logger->error('A terminal was revoked but its sessions could not be revoked on the master.', [
                'tenant_id' => $tenantId,
                'terminal_id' => $terminalId,
                'exception' => $failure::class,
            ]);

            return false;
        }
    }
}
