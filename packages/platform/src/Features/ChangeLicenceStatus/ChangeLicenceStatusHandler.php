<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeLicenceStatus;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Support\LicenceStatus;
use Flexipos\Platform\Support\MasterConnection;
use Flexipos\Platform\Support\PlatformAudit;
use Flexipos\Platform\Support\TenantStatusSync;

/**
 * Change a licence's status (T-016 Ruling 16, AC-25).
 *
 * Writes `status`, and `revoked_at`/`revoked_reason` for `revoked`. It never
 * edits the validity window - `valid_from`/`valid_until` are set once, when
 * the licence is issued, by whatever later task issues one.
 */
final class ChangeLicenceStatusHandler
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly RunnerClock $clock,
        private readonly PlatformAudit $audit,
        private readonly TenantStatusSync $statusSync,
    ) {}

    public function handle(ChangeLicenceStatus $command): ChangeLicenceStatusResult
    {
        if (! in_array($command->status, LicenceStatus::ALL, true)) {
            throw PlatformRefused::validation("Unknown licence status [{$command->status}].");
        }

        $connection = $this->master->get();

        return $connection->transaction(function () use ($connection, $command): ChangeLicenceStatusResult {
            $licence = $connection->table('licences')->where('id', $command->licenceId)->lockForUpdate()->first();

            if ($licence === null) {
                throw PlatformRefused::notFound("No licence [{$command->licenceId}].");
            }

            $now = $this->clock->timestamp();
            $updates = ['status' => $command->status, 'updated_at' => $now];

            if ($command->status === LicenceStatus::REVOKED) {
                $updates['revoked_at'] = $now;
                $updates['revoked_reason'] = $command->revokedReason;
            }

            $connection->table('licences')->where('id', $command->licenceId)->update($updates);

            $this->audit->write(
                $connection,
                'licence_status_changed',
                'licence',
                $command->licenceId,
                (string) $licence->tenant_id,
                $command->actorUserId,
                ['status' => $licence->status],
                ['status' => $command->status],
            );

            $trigger = $this->statusSync->reconcile($connection, (string) $licence->tenant_id, $command->actorUserId);

            return new ChangeLicenceStatusResult($command->licenceId, $trigger);
        });
    }
}
