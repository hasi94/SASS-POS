<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;

/*
|--------------------------------------------------------------------------
| File-scope helpers shared by this package's tests (T-032 plan section 3)
|--------------------------------------------------------------------------
|
| `require_once` rather than relying on another test file having been
| collected first (the FP-164 shape every other package fixture follows).
*/

/**
 * Enter the fixture tenant. `boot()` is each test file's own `beforeEach`
 * call, and only `enter()` happens here, so the per-test cost stays visible
 * to a reader scanning the test file.
 */
function financeEnterTenant(string $tenant = 'tenant-a'): void
{
    AccessTenantFixture::enter($tenant);
}
