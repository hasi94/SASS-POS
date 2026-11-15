<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeSubscriptionPlan;

use Flexipos\Contracts\Platform\PlanLimitReached;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Illuminate\Console\Command;

/**
 * `php artisan platform:change-subscription-plan` (T-016 AC-33, D-21).
 *
 * The console entry point for the write a platform operator makes with no
 * HTTP surface to go through: internal handler plus one command, exactly as
 * D-21 scopes it.
 */
final class ChangeSubscriptionPlanCommand extends Command
{
    protected $signature = 'platform:change-subscription-plan
        {subscription : The subscription id}
        {plan : The new plan id}
        {--actor= : The acting user id, recorded on the audit row}';

    protected $description = "Change a tenant's current subscription to a different plan";

    public function handle(ChangeSubscriptionPlanHandler $handler): int
    {
        try {
            $result = $handler->handle(new ChangeSubscriptionPlan(
                subscriptionId: (string) $this->argument('subscription'),
                planId: (string) $this->argument('plan'),
                actorUserId: $this->option('actor') === null ? null : (string) $this->option('actor'),
            ));
        } catch (PlatformRefused|PlanLimitReached $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Subscription {$result->subscriptionId} is now on plan {$result->planId}.");

        return self::SUCCESS;
    }
}
