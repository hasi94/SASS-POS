<?php

use Flexipos\Kernel\Tenancy\CachingTenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotActive;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA, T-008, AC-53 half (b): a `migrating` mapping is refused for serving
|--------------------------------------------------------------------------
|
| The spec records half (b) as true of the code and asserted nowhere: the
| non-active refusal dataset in TenancyResolutionTest holds `suspended` and
| `provisioning` only, and closing it is a dataset row dev adds to that
| T-007 file. This file asserts it from outside that file, through the
| shipped graph (the master-backed reader behind the caching decorator), so it
| does not depend on that row landing and does not edit a T-007 test.
|
| It is **half (b) only**. It says nothing about half (a), and AC-53 is
| [E] and stays NOT VERIFIED behind ENGINE_HOLD (G-1) whatever this file does.
|
| No run, no second process and no engine: one mapping row whose status is
| `migrating`, one serving entry, and the AC-10 assertions that the refusal
| left the tenant stanza and the tenant context exactly as they were. The
| control is the same mapping at `active`, which is served.
*/

require_once __DIR__.'/../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();
    migrateFixtureServer();
});

afterEach(function () {
    migrateFixtureTearDown();
});

it('refuses a serving entry for a migrating mapping, naming the status, and changes nothing', function () {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'), ['status' => 'migrating']);
    $before = tenancyTenantStanza();

    expect($before)->toBe(tenancyPlaceholderStanza());

    expect(function () use ($tenant): void {
        tenancy()->enter($tenant);
    })->toThrow(TenantDatabaseNotActive::class, 'migrating');

    expect(tenancyTenantStanza())->toBe($before)
        ->and(tenancy()->current()->hasTenant())->toBeFalse();
});

it('serves the same mapping once it is active again (the control)', function () {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'), ['status' => 'migrating']);

    // The same row, moved to active the way the runner moves it: through the
    // master, with the cached mapping evicted so the new value is read.
    DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenant)->update(['status' => 'active']);
    $repository = app(TenantDatabaseRepository::class);

    expect($repository)->toBeInstanceOf(CachingTenantDatabaseRepository::class);

    $repository->evict($tenant);

    tenancy()->enter($tenant);

    expect(tenancy()->current()->tenantId())->toBe($tenant);

    tenancy()->leave();
});
