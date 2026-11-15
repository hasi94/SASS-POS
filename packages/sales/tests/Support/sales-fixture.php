<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/*
|--------------------------------------------------------------------------
| File-scope helpers shared by this package's tests (T-030 plan section 3)
|--------------------------------------------------------------------------
|
| `require_once` rather than relying on another test file having been collected
| first (the FP-164 shape every other package fixture avoids the same way).
| `boot()` is each test file's own `beforeEach` call, and only the entering
| happens here: a helper that also booted would hide the per-test cost from a
| reader scanning the test file.
*/

/**
 * Enter the fixture tenant and pin the clock.
 */
function salesEnterTenant(string $tenant = 'tenant-a', string $now = '2026-11-12 10:00:00.000'): void
{
    AccessTenantFixture::enter($tenant);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt($now));
}
