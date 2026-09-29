<?php

use App\Providers\AppServiceProvider;
use App\Providers\ServiceHostServiceProvider;
use Flexipos\Kernel\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    ServiceHostServiceProvider::class,
    TenancyServiceProvider::class,
];
