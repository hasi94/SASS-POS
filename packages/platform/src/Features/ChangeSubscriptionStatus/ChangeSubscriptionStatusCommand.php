<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeSubscriptionStatus;

use Flexipos\Platform\Exceptions\PlatformRefused;
use Illuminate\Console\Command;

/**
 * `php artisan platform:change-subscription-status` (T-016 AC-33, D-21).
 *
 * The console entry point for the write a platform operator makes with no
 * HTTP surface to go through: internal handler plus one command, exactly as
 * D-21 scopes it.
 */
final class ChangeSubscriptionStatusCommand extends Command
{
    protected $signature = 'platform:change-subscription-status
        {subscription : The subscription id}
        {status : trial, active, past_due, paused, cancelled or expired}
        {--reason= : The cancellation reason, recorded when the status is cancelled}
        {--actor= : The acting user id, recorded on the audit row}';

    protected $description = "Change a subscription's status, reconciling the tenant's commercial status in the same write";

    public function handle(ChangeSubscriptionStatusHandler $handler): int
    {
        try {
            $result = $handler->handle(new ChangeSubscriptionStatus(
                subscriptionId: (string) $this->argument('subscription'),
                status: (string) $this->argument('status'),
                cancellationReason: $this->option('reason') === null ? null : (string) $this->option('reason'),
                actorUserId: $this->option('actor') === null ? null : (string) $this->option('actor'),
            ));
        } catch (PlatformRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "Subscription {$result->subscriptionId} is now {$this->argument('status')}."
            .($result->trigger === null ? ' The tenant status was unchanged.' : " Tenant status reconciled, trigger [{$result->trigger}].")
        );

        return self::SUCCESS;
    }
}
