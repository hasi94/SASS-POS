<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\ResolvePlanLimit;

use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Contracts\Platform\PlanLimits;
use Flexipos\Kernel\Tenancy\Exceptions\MappingSourceNotPermitted;
use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\MappingSource;
use Flexipos\Kernel\Tenancy\TenantContext;
use Flexipos\Kernel\Tenancy\TenantDatabase;
use Flexipos\Platform\Support\CachingEntitlementSource;
use Flexipos\Platform\Support\MasterEntitlementSource;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 7 - entitlements and PlanLimits (AC-3 resolution half, AC-5)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
});

it('a plan with a branches limit and no terminals row caps one and leaves the other uncapped', function () {
    PlatformSeed::bindMasterSource();

    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    PlatformSeed::planLimit($planId, 'branches', 5);
    PlatformSeed::subscription($tenantId, $planId, 'active');

    app(TenantContext::class)->resolved($tenantId, new TenantDatabase(
        tenantId: $tenantId, driver: 'sqlite', host: '', port: 0, database: ':memory:', username: '', credentialRef: '', status: 'active', schemaVersion: 1, credentialVersion: 1,
    ));

    $planLimits = app(PlanLimits::class);

    expect($planLimits->forCurrentTenant(LimitKey::Branches)->value)->toBe(5)
        ->and($planLimits->forCurrentTenant(LimitKey::Terminals)->value)->toBeNull();
});

it('a negative plan_limits.value is treated as 0', function () {
    PlatformSeed::bindMasterSource();

    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    PlatformSeed::planLimit($planId, 'branches', -3);
    PlatformSeed::subscription($tenantId, $planId, 'active');

    app(TenantContext::class)->resolved($tenantId, new TenantDatabase(
        tenantId: $tenantId, driver: 'sqlite', host: '', port: 0, database: ':memory:', username: '', credentialRef: '', status: 'active', schemaVersion: 1, credentialVersion: 1,
    ));

    expect(app(PlanLimits::class)->forCurrentTenant(LimitKey::Branches)->value)->toBe(0);
});

it('a cache hit never re-reads the master, and evict() drops exactly one tenant', function () {
    $tenantA = PlatformSeed::tenant('tenant-a', 'active');
    $tenantB = PlatformSeed::tenant('tenant-b', 'active');
    $planId = PlatformSeed::plan();
    PlatformSeed::planLimit($planId, 'branches', 5);
    PlatformSeed::subscription($tenantA, $planId, 'active');
    PlatformSeed::subscription($tenantB, $planId, 'active');

    $caching = new CachingEntitlementSource(app(MasterEntitlementSource::class), app('cache.store'), 300);

    $first = $caching->for($tenantA);

    $connection = PlatformSeed::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $second = $caching->for($tenantA);
    $queries = $connection->getQueryLog();
    $connection->disableQueryLog();

    expect($queries)->toBe([])
        ->and($second->limits)->toBe($first->limits);

    $caching->evict($tenantA);
    $caching->evict($tenantB); // dropping a tenant with nothing cached is a no-op, not an error.

    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $caching->for($tenantA);
    $afterEviction = $connection->getQueryLog();
    $connection->disableQueryLog();

    expect($afterEviction)->not->toBe([]);
});

it('refuses outside local environments', function () {
    config()->set('tenancy.mapping.source', 'config');
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => MappingSource::isConfigStandIn(app('config'), app()))
        ->toThrow(MappingSourceNotPermitted::class);
});

it('throws NoTenantResolved rather than an uncapped answer when no tenant is resolved', function () {
    expect(fn () => app(PlanLimits::class)->forCurrentTenant(LimitKey::Branches))
        ->toThrow(NoTenantResolved::class);
});
