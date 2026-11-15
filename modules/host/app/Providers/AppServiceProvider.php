<?php

namespace App\Providers;

use App\Support\Api\ApiLimits;
use App\Support\Health\MasterDatabaseCheck;
use App\Support\Health\ReadinessProbe;
use App\Support\Health\RedisCheck;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * T-021 AC-33: built once, at boot, from `config('api.limits')`, so a
     * bad value (0, negative, non-integer) fails the whole application
     * rather than silently disabling throttling the first time a request
     * needs a limit.
     */
    public function register(): void
    {
        $this->app->singleton(
            ApiLimits::class,
            static fn ($app): ApiLimits => ApiLimits::fromConfig(
                (array) $app->make(ConfigRepository::class)->get('api.limits', [])
            )
        );

        // T-021 Ruling 7. Bound by interface so a test can bind a throwing
        // fake over either check (AC-43, AC-44) without touching a real
        // connection. Not a singleton: a readiness probe must build its
        // checks fresh from whatever bindings are current on each request.
        $this->app->bind(
            ReadinessProbe::class,
            static fn ($app): ReadinessProbe => new ReadinessProbe([
                $app->make(MasterDatabaseCheck::class),
                $app->make(RedisCheck::class),
            ])
        );
    }

    /**
     * Bootstrap any application services.
     *
     * Resolved eagerly here, not left to the first request that needs it:
     * `ApiLimits::fromConfig()` throws on a bad value, and that throw must
     * happen while the application boots.
     */
    public function boot(): void
    {
        $this->app->make(ApiLimits::class);
    }
}
