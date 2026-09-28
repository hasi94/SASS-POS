<?php

namespace App\Providers;

use App\Support\ServiceHost\ServiceRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class ServiceHostServiceProvider extends ServiceProvider
{
    /**
     * Boot the service packages named in SERVICES.
     *
     * This happens during the registration phase on purpose: a misconfigured
     * SERVICES value must break the process at boot, not lazily on the first
     * request that happens to hit a missing route.
     */
    public function register(): void
    {
        $this->app->singleton(ServiceRegistry::class, fn (Application $app): ServiceRegistry => new ServiceRegistry($app));

        $services = config('servicehost.services', '');

        $this->app->make(ServiceRegistry::class)->bootAll(is_string($services) ? $services : '');
    }
}
