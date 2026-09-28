<?php

namespace Flexipos\Service\Demo;

use Illuminate\Support\ServiceProvider;

class DemoServiceProvider extends ServiceProvider
{
    /**
     * The database connection this service owns. No other service may use it.
     */
    public const CONNECTION = 'svc_demo';

    /**
     * The route prefix every endpoint of this service is mounted under.
     */
    public const PREFIX = 'api/v1/demo';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/connection.php', 'database.connections');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
