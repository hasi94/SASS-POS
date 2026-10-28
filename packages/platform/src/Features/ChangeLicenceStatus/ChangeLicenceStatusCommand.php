<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeLicenceStatus;

use Flexipos\Platform\Exceptions\PlatformRefused;
use Illuminate\Console\Command;

/**
 * `php artisan platform:change-licence-status` (T-016 AC-33, D-21).
 *
 * The console entry point for the write a platform operator makes with no
 * HTTP surface to go through: internal handler plus one command, exactly as
 * D-21 scopes it.
 */
final class ChangeLicenceStatusCommand extends Command
{
    protected $signature = 'platform:change-licence-status
        {licence : The licence id}
        {status : active, expired, revoked or suspended}
        {--reason= : The revocation reason, recorded when the status is revoked}
        {--actor= : The acting user id, recorded on the audit row}';

    protected $description = "Change a licence's status, reconciling the tenant's commercial status in the same write";

    public function handle(ChangeLicenceStatusHandler $handler): int
    {
        try {
            $result = $handler->handle(new ChangeLicenceStatus(
                licenceId: (string) $this->argument('licence'),
                status: (string) $this->argument('status'),
                revokedReason: $this->option('reason') === null ? null : (string) $this->option('reason'),
                actorUserId: $this->option('actor') === null ? null : (string) $this->option('actor'),
            ));
        } catch (PlatformRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "Licence {$result->licenceId} is now {$this->argument('status')}."
            .($result->trigger === null ? ' The tenant status was unchanged.' : " Tenant status reconciled, trigger [{$result->trigger}].")
        );

        return self::SUCCESS;
    }
}
