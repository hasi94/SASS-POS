<?php

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/*
|--------------------------------------------------------------------------
| File-scope helpers shared by this package's slice tests (T-034 plan section 3)
|--------------------------------------------------------------------------
|
| `require_once` rather than relying on another test file having been collected
| first (the FP-164 shape every other package fixture uses). `boot()` stays each
| test file's own `beforeEach` call; only the entering, the clock and the
| permission registry happen here.
*/

/**
 * Enter the fixture tenant, pin the clock and register this package's permission
 * keys (the real registry sync), so a role can be granted them.
 */
function puEnterTenant(string $tenant = 'tenant-a', string $now = '2026-11-16 09:00:00.000'): void
{
    AccessTenantFixture::enter($tenant);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt($now));

    /** @var SyncPermissionRegistryHandler $sync */
    $sync = app(SyncPermissionRegistryHandler::class);
    $sync->handle(new SyncPermissionRegistry);
}

/**
 * An employee holding these keys, company-wide unless a branch is given.
 *
 * @param  list<string>  $keys
 */
function puActor(string $code, array $keys, ?string $branchId = null): string
{
    return AccessSeed::actorHolding($code, $keys, $branchId);
}
