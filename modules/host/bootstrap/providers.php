<?php

use App\Providers\AppServiceProvider;
use App\Providers\ModuleHostServiceProvider;
use Flexipos\Access\AccessServiceProvider;
use Flexipos\Auth\AuthServiceProvider;
use Flexipos\Kernel\Schema\SchemaServiceProvider;
use Flexipos\Kernel\Tenancy\TenancyServiceProvider;
use Flexipos\Organization\OrganizationServiceProvider;
use Flexipos\Platform\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    ModuleHostServiceProvider::class,
    TenancyServiceProvider::class,
    SchemaServiceProvider::class,
    AuthServiceProvider::class,
    AccessServiceProvider::class,
    OrganizationServiceProvider::class,
    PlatformServiceProvider::class,
];
