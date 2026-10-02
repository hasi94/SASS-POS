<?php

use App\Providers\AppServiceProvider;
use App\Providers\ModuleHostServiceProvider;
use Flexipos\Kernel\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    ModuleHostServiceProvider::class,
    TenancyServiceProvider::class,
];
