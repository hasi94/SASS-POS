<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;

/*
|--------------------------------------------------------------------------
| File-scope helpers shared by this package's tests (T-028 plan section 3)
|--------------------------------------------------------------------------
|
| `require_once` rather than relying on another test file having been
| collected first (the FP-164 shape every other package fixture avoids the
| same way).
*/

/**
 * Enter the fixture tenant - `boot()` is each test file's own `beforeEach`
 * call, and only `enter()` happens here (the memory note every package
 * fixture follows: a helper that also booted would hide the per-test cost
 * from a reader scanning the test file).
 */
function customersEnterTenant(string $tenant = 'tenant-a'): void
{
    AccessTenantFixture::enter($tenant);
}
