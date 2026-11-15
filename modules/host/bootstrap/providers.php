<?php

use App\Providers\AppServiceProvider;
use App\Providers\ModuleHostServiceProvider;
use Flexipos\Access\AccessServiceProvider;
use Flexipos\Auth\AuthServiceProvider;
use Flexipos\Catalog\CatalogServiceProvider;
use Flexipos\Customers\CustomersServiceProvider;
use Flexipos\Eventbus\EventbusServiceProvider;
use Flexipos\Kernel\Api\ApiServiceProvider;
use Flexipos\Kernel\Schema\SchemaServiceProvider;
use Flexipos\Kernel\Tenancy\TenancyServiceProvider;
use Flexipos\Organization\OrganizationServiceProvider;
use Flexipos\Platform\PlatformServiceProvider;
use Flexipos\PluginSdk\PluginSdkServiceProvider;
use Flexipos\Pricing\PricingServiceProvider;
use Flexipos\Sales\SalesServiceProvider;
use Flexipos\Workflow\WorkflowServiceProvider;

return [
    AppServiceProvider::class,
    ModuleHostServiceProvider::class,
    TenancyServiceProvider::class,
    SchemaServiceProvider::class,
    ApiServiceProvider::class,
    AuthServiceProvider::class,
    AccessServiceProvider::class,
    OrganizationServiceProvider::class,
    PlatformServiceProvider::class,
    EventbusServiceProvider::class,
    PluginSdkServiceProvider::class,
    WorkflowServiceProvider::class,
    CatalogServiceProvider::class,
    CustomersServiceProvider::class,
    PricingServiceProvider::class,
    SalesServiceProvider::class,
];
