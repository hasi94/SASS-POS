<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ChangeTerminalStatus;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalLookup;
use Flexipos\Organization\Support\TerminalStatus;

/**
 * Pause an `active` terminal and resume an `inactive` one (T-014 AC-37,
 * D-15).
 *
 * **Only the state, never the credential**: the credential row and the device
 * rows are left exactly as they are, because pausing is reversible and a
 * resumed till must keep working with the token it already holds. The token
 * stops working at once through the request-time check (AC-34) and its
 * refresh families stay intact (D-35); neither is this slice's to touch.
 *
 * Refusals: a `revoked` terminal is `terminal_revoked`; a `pending` one
 * cannot be paused or resumed (`terminal_not_active`); pausing an `inactive`
 * terminal or resuming an `active` one is `validation` - there is no state to
 * change - and writes no audit row; a status other than the two is
 * `validation`.
 */
final class ChangeTerminalStatusHandler
{
    public const ACTION_DEACTIVATED = 'terminal_deactivated';

    public const ACTION_REACTIVATED = 'terminal_reactivated';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(ChangeTerminalStatus $command): void
    {
        if ($command->status !== TerminalStatus::INACTIVE && $command->status !== TerminalStatus::ACTIVE) {
            throw OrganizationRefused::validation('The status must be inactive (pause) or active (resume).');
        }

        $wanted = $command->status;
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $wanted): void {
            $terminal = TerminalLookup::find($connection, $command->terminalId, lock: true);
            $id = (string) $terminal->id;
            $current = (string) $terminal->status;

            if ($current === TerminalStatus::REVOKED) {
                throw OrganizationRefused::terminalRevoked("Terminal [{$id}] is revoked.");
            }

            if ($current === TerminalStatus::PENDING) {
                throw OrganizationRefused::terminalNotActive("Terminal [{$id}] has never been activated.");
            }

            if ($current === $wanted) {
                throw OrganizationRefused::validation("Terminal [{$id}] is already {$wanted}.");
            }

            $connection->table('terminals')->where('id', $id)->update([
                'status' => $wanted,
                'updated_at' => $this->clock->timestamp(),
            ]);

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                $wanted === TerminalStatus::INACTIVE ? self::ACTION_DEACTIVATED : self::ACTION_REACTIVATED,
                'terminal',
                $id,
                ['status' => $current],
                ['status' => $wanted],
                (string) $terminal->branch_id,
            );
        });
    }
}
