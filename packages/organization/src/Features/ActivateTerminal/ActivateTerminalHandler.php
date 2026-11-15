<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ActivateTerminal;

use Flexipos\Contracts\Access\TerminalLoginLog;
use Flexipos\Contracts\Organization\ActivatedTerminal;
use Flexipos\Contracts\Organization\ActivationAttempt;
use Flexipos\Contracts\Organization\ActivationCodeFormat;
use Flexipos\Contracts\Organization\TerminalActivation;
use Flexipos\Contracts\Organization\TerminalActivationRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminalHandler;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCodeHandler;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Support\TerminalStatus;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * The tenant half of terminal activation (T-014 AC-29, AC-30, AC-32, D-16,
 * D-17, D-20, D-34).
 *
 * **One tenant transaction, in this order, with every refusal before the first
 * write:**
 *
 * 1. Lock the terminal row `where activation_code_hash = ? and status =
 *    'pending'`. No row: the code is unknown, used, or belongs to a terminal
 *    that is not pending (revoked, paused, active) - one reason class, because
 *    the caller is told nothing.
 * 2. Refuse if the code has expired (`now >= activation_expires_at`, so the
 *    expiry instant itself is expired), if the terminal's branch is not
 *    active, or if the fingerprint is the live device of **another** terminal
 *    (D-16): a fingerprint is one device, and it can move only after its
 *    terminal is revoked.
 * 3. Insert the device; update the terminal **conditionally** (`where status =
 *    'pending' and activation_code_hash = ?`, exactly one row) to `active` with
 *    `current_device_id` and `last_seen_at`, clearing the hash and the expiry -
 *    the code is single use; insert the credential; write the `terminal_login`
 *    access-log row through access's contract.
 * 4. Call the caller's callable, which mints the master refresh row, and
 *    return what it returns. If it throws, this transaction rolls back and no
 *    tenant row survives; if the commit then fails, a refresh row is left
 *    behind a still-pending terminal, which is harmless: the terminal refresh
 *    refuses it.
 *
 * **`activated_by` is the actor of the newest audit row that issued the code
 * in force** (Ruling 4, D-34): `terminal_created` or `activation_code_issued`
 * for this terminal. No column holds the issuer (AC-1 forbids one), so the
 * read is of `audit_logs`, and the two action names are the constants the
 * issuing slices write with - one home each, read here.
 */
final class ActivateTerminalHandler implements TerminalActivation
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly TerminalLoginLog $loginLog,
    ) {}

    public function activate(ActivationAttempt $attempt, callable $issueInsideTransaction): mixed
    {
        return $this->handle(new ActivateTerminal($attempt), $issueInsideTransaction);
    }

    /**
     * @param  callable(ActivatedTerminal): mixed  $issueInsideTransaction
     *
     * @throws TerminalActivationRefused before any write
     */
    public function handle(ActivateTerminal $command, callable $issueInsideTransaction): mixed
    {
        $attempt = $command->attempt;
        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $attempt, $issueInsideTransaction): mixed {
            $hash = ActivationCodeFormat::hashOf($attempt->secret);

            $terminal = $connection->table('terminals')
                ->where('activation_code_hash', $hash)
                ->where('status', TerminalStatus::PENDING)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($terminal === null) {
                throw TerminalActivationRefused::because(TerminalActivationRefused::UNKNOWN_CODE);
            }

            $id = (string) $terminal->id;
            $branchId = (string) $terminal->branch_id;
            $now = $this->clock->now();

            if ($terminal->activation_expires_at === null
                || $now->format(RunnerClock::PRECISION) >= (string) $terminal->activation_expires_at) {
                throw TerminalActivationRefused::because(TerminalActivationRefused::EXPIRED);
            }

            $branchActive = $connection->table('branches')
                ->where('id', $branchId)
                ->whereNull('deleted_at')
                ->value('is_active');

            if (! (bool) $branchActive) {
                throw TerminalActivationRefused::because(TerminalActivationRefused::BRANCH_INACTIVE);
            }

            $inUse = $connection->table('devices')
                ->where('fingerprint', $attempt->fingerprint)
                ->whereNull('revoked_at')
                ->where('terminal_id', '!=', $id)
                ->exists();

            if ($inUse) {
                throw TerminalActivationRefused::because(TerminalActivationRefused::DEVICE_IN_USE);
            }

            $stamp = $now->format(RunnerClock::PRECISION);
            $deviceId = (string) Str::ulid();

            $connection->table('devices')->insert([
                'id' => $deviceId,
                'terminal_id' => $id,
                'fingerprint' => $attempt->fingerprint,
                'platform' => $attempt->platform,
                'browser' => $attempt->browser,
                'os' => $attempt->os,
                'screen' => $attempt->screen,
                'registered_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            $activated = $connection->table('terminals')
                ->where('id', $id)
                ->where('status', TerminalStatus::PENDING)
                ->where('activation_code_hash', $hash)
                ->update([
                    'status' => TerminalStatus::ACTIVE,
                    'current_device_id' => $deviceId,
                    'last_seen_at' => $stamp,
                    'activation_code_hash' => null,
                    'activation_expires_at' => null,
                    'updated_at' => $stamp,
                ]);

            if ($activated !== 1) {
                // Another request consumed the code between the lock read and
                // here: this one gets the same refusal, and the device row it
                // inserted goes with the rollback.
                throw TerminalActivationRefused::because(TerminalActivationRefused::UNKNOWN_CODE);
            }

            $connection->table('terminal_credentials')->insert([
                'terminal_id' => $id,
                'device_fingerprint' => $attempt->fingerprint,
                'status' => 'active',
                'activated_at' => $stamp,
                'activated_by' => $this->issuerOf($connection, $id),
            ]);

            $this->loginLog->record($id, $branchId, $attempt->ip);

            return $issueInsideTransaction(new ActivatedTerminal($id, $branchId));
        });
    }

    /**
     * The employee who issued the code in force, from the newest issuing
     * audit row of this terminal, or null when there is none.
     */
    private function issuerOf(Connection $connection, string $terminalId): ?string
    {
        $actor = $connection->table('audit_logs')
            ->where('subject_type', 'terminal')
            ->where('subject_id', $terminalId)
            ->whereIn('action', [CreateTerminalHandler::ACTION, ReissueActivationCodeHandler::ACTION])
            ->orderByDesc('id')
            ->value('actor_id');

        return $actor === null ? null : (string) $actor;
    }
}
