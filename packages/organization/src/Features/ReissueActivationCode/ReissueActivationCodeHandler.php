<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ReissueActivationCode;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminalResult;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\ActivationIssuer;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalLookup;
use Flexipos\Organization\Support\TerminalStatus;

/**
 * Re-issue the activation code of a `pending` terminal (T-014 AC-28, D-15).
 *
 * The new code and expiry replace the old, so **the old code now fails
 * activation**: its hash is gone from the row. An expired `pending` terminal
 * can be re-issued, which is the whole use of the operation. Any other state
 * is refused with nothing changing: a revoked terminal is `terminal_revoked`
 * (final, D-15), an `active` or `inactive` one `terminal_not_pending` (its code
 * was consumed). The result is {@see CreateTerminalResult}'s shape: the
 * plaintext once.
 *
 * The audit row records that a code was issued and when it expires, and
 * neither the code nor the hash. Its action name is the one constant the
 * activation reads the issuer back by (Ruling 4, D-34).
 */
final class ReissueActivationCodeHandler
{
    public const ACTION = 'activation_code_issued';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly ActivationIssuer $issuer,
    ) {}

    public function handle(ReissueActivationCode $command): CreateTerminalResult
    {
        $connection = $this->tenant->get();

        // Made before the transaction, so an unusable tenant code stops here
        // with nothing written (Ruling 7); a refused request discards it.
        $issued = $this->issuer->issue();

        return $connection->transaction(function () use ($connection, $command, $issued): CreateTerminalResult {
            $terminal = TerminalLookup::find($connection, $command->terminalId, lock: true);
            $id = (string) $terminal->id;

            if ((string) $terminal->status === TerminalStatus::REVOKED) {
                throw OrganizationRefused::terminalRevoked("Terminal [{$id}] is revoked.");
            }

            if ((string) $terminal->status !== TerminalStatus::PENDING) {
                throw OrganizationRefused::terminalNotPending("Terminal [{$id}] is not pending.");
            }

            $connection->table('terminals')->where('id', $id)->update([
                'activation_code_hash' => $issued['hash'],
                'activation_expires_at' => $issued['expires_at'],
                'updated_at' => $this->clock->timestamp(),
            ]);

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

            return new CreateTerminalResult($id, $issued['code'], $issued['expires_at']);
        });
    }
}
