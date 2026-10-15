<?php

use App\Providers\AppServiceProvider;
use App\Providers\ModuleHostServiceProvider;
use Flexipos\Kernel\Schema\SchemaServiceProvider;
use Flexipos\Kernel\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    ModuleHostServiceProvider::class,
    TenancyServiceProvider::class,
    SchemaServiceProvider::class,
];
