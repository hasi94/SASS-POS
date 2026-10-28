<?php

declare(strict_types=1);

namespace Flexipos\Platform;

use Flexipos\Contracts\Platform\FeatureFlags;
use Flexipos\Contracts\Platform\PlanLimits;
use Flexipos\Contracts\Platform\TenantSeats;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\MappingSource;
use Flexipos\Platform\Features\ChangeLicenceStatus\ChangeLicenceStatusCommand;
use Flexipos\Platform\Features\ChangeSubscriptionPlan\ChangeSubscriptionPlanCommand;
use Flexipos\Platform\Features\ChangeSubscriptionStatus\ChangeSubscriptionStatusCommand;
use Flexipos\Platform\Features\CheckSeatAvailability\CheckSeatAvailabilityHandler;
use Flexipos\Platform\Features\EnforceCommercialDeadlines\EnforceCommercialDeadlinesCommand;
use Flexipos\Platform\Features\EnforceCommercialDeadlines\EnforceCommercialDeadlinesHandler;
use Flexipos\Platform\Features\ResolveFeatureFlag\ResolveFeatureFlagHandler;
use Flexipos\Platform\Features\ResolvePlanLimit\ResolvePlanLimitHandler;
use Flexipos\Platform\Features\SetTenantFeatureFlag\SetTenantFeatureFlagCommand;
use Flexipos\Platform\Support\CachingEntitlementSource;
use Flexipos\Platform\Support\ConfigEntitlementSource;
use Flexipos\Platform\Support\EntitlementSource;
use Flexipos\Platform\Support\EvictsEntitlements;
use Flexipos\Platform\Support\MasterConnection;
use Flexipos\Platform\Support\MasterEntitlementSource;
use Flexipos\Platform\Support\PlatformAudit;
use Flexipos\Platform\Support\TenantStatusSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * The platform package's entry point into the host application (T-016).
 *
 * **A package and not a `modules/*` module** (plan section 1, approach
 * summary item 1): other packages read plans, subscriptions, licensing and
 * flags through the contracts this provider binds, never through this
 * package's own classes.
 *
 * **One binding decides the whole entitlement path** (Ruling 12). Under the
 * config mapping stand-in - `MappingSource::isConfigStandIn()` - the source is
 * `ConfigEntitlementSource`, which models "no plan" as a real, uncapped
 * state rather than stubbing a guard to allow. Otherwise it is
 * `MasterEntitlementSource` behind the same 300-second cache window the
 * kernel's tenant-mapping cache uses.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EntitlementSource::class, function (Application $app): EntitlementSource {
            $config = $app->make(ConfigRepository::class);

            if (MappingSource::isConfigStandIn($config, $app)) {
                return $app->make(ConfigEntitlementSource::class);
            }

            return new CachingEntitlementSource(
                $app->make(MasterEntitlementSource::class),
                $app->make(CacheRepository::class),
                (int) $config->get('platform.entitlements_ttl', 300),
            );
        });

        $this->app->singleton(EvictsEntitlements::class, fn (Application $app) => $app->make(EntitlementSource::class));

        $this->app->bind(TenantStatusSync::class, fn (Application $app) => new TenantStatusSync(
            $app->make(RunnerClock::class),
            $app->make(PlatformAudit::class),
            (int) $app->make(ConfigRepository::class)->get('platform.past_due_grace_days', 7),
        ));

        $this->app->bind(EnforceCommercialDeadlinesHandler::class, fn (Application $app) => new EnforceCommercialDeadlinesHandler(
            $app->make(MasterConnection::class),
            $app->make(RunnerClock::class),
            $app->make(TenantStatusSync::class),
            (int) $app->make(ConfigRepository::class)->get('platform.past_due_grace_days', 7),
        ));

        $this->app->singleton(PlanLimits::class, ResolvePlanLimitHandler::class);
        $this->app->singleton(FeatureFlags::class, ResolveFeatureFlagHandler::class);
        $this->app->singleton(TenantSeats::class, CheckSeatAvailabilityHandler::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                EnforceCommercialDeadlinesCommand::class,
                // AC-33, D-21: the console entry point for each of this
                // task's writes, since none of them has an HTTP route.
                ChangeSubscriptionPlanCommand::class,
                ChangeSubscriptionStatusCommand::class,
                ChangeLicenceStatusCommand::class,
                SetTenantFeatureFlagCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            $schedule->command(EnforceCommercialDeadlinesCommand::class)
                ->everyMinute()
                ->withoutOverlapping();
        });
    }
}
