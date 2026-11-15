<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Secrets\CredentialGenerator;
use Flexipos\Platform\Support\MasterConnection;
use Illuminate\Support\Facades\Hash;

/**
 * D-31's rotation precondition and write, extracted into one shared service
 * (review round 2 R-3 - the residual half of R-1).
 *
 * `create_owner` itself originally carried this logic inline, wired only
 * into its own re-entry path; round 2 found that misses every process death
 * *after* `create_owner` is already `done` (during `verify`, `activate`,
 * `markRunCompleted`, a SIGTERM/SSH-disconnect/OOM/PHP-fatal - none of which
 * are a catchable `Throwable`). This class is now the one place the
 * precondition and the update are stated, callable from three places:
 * `create_owner`'s own re-entry branch, `ProvisionTenantHandler::resume()`
 * (when the announcement came back empty but `create_owner`'s own output
 * already shows a password-bearing mode) and the new, separate
 * `--reissue-owner-password=<run_id>` command, which must also work against
 * an already-`completed` run - the precondition, not the run's own status,
 * is what gates it.
 *
 * **The precondition, unchanged from D-31:** `must_change_password = true`
 * AND `last_login_at IS NULL` on the row named by `$userId` - provably never
 * signed into, by anyone, ever. A single successful sign-in anywhere closes
 * this path for that row permanently, with no code change needed elsewhere.
 *
 * **The review's one Suggestion, folded in for free:** the row is read with
 * `lockForUpdate()` inside the same transaction as the update, closing a
 * sub-second concurrent-first-login race between this read and the write -
 * the precondition that mattered a moment ago, under the lock, is the one
 * this method actually acts on.
 */
final class OwnerPasswordReissue
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly RunnerClock $clock,
    ) {}

    /**
     * @return array{eligible: bool, password: ?string}
     */
    public function reissue(string $userId): array
    {
        $master = $this->master->get();

        /** @var array{eligible: bool, password: ?string} $result */
        $result = $master->transaction(function () use ($master, $userId): array {
            $row = $master->table('users')->where('id', $userId)->lockForUpdate()->first();

            if ($row === null) {
                return ['eligible' => false, 'password' => null];
            }

            /** @var object{must_change_password: mixed, last_login_at: mixed} $row */
            if (! (bool) $row->must_change_password || $row->last_login_at !== null) {
                return ['eligible' => false, 'password' => null];
            }

            $password = CredentialGenerator::generate()->reveal();

            $master->table('users')->where('id', $userId)->update([
                'password_hash' => Hash::make($password),
                'updated_at' => $this->clock->timestamp(),
            ]);

            return ['eligible' => true, 'password' => $password];
        });

        return $result;
    }
}
