<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| File-scope helpers shared by the T-019 slice and gateway tests
|--------------------------------------------------------------------------
|
| Built on Flexipos\Access\Tests\Support\AccessTenantFixture, not beside it
| - the same eventbus-fixture.php precedent.
|
| `AccessTenantFixture::boot()` is called once per test, in `beforeEach`;
| afterwards only an `enter` helper below is called (memory: boot() deletes
| and re-copies every tenant's database from the template on each call, so
| calling it more than once per test would silently wipe out whatever a
| test had already written).
|
*/

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

if (! function_exists('pluginEnterTenant')) {
    /**
     * Enter a fixture tenant to serve it - the shape most gateway tests
     * need. Delegates whole to `AccessTenantFixture::enter()`.
     */
    function pluginEnterTenant(string $tenant = 'tenant-a'): void
    {
        AccessTenantFixture::enter($tenant);
    }
}

if (! function_exists('pluginEnterTenantForMigration')) {
    /**
     * Enter a fixture tenant for the migrate credential, with SQLite's
     * foreign-key enforcement on - `AccessTenantFixture::enter()`'s own
     * pragma, applied here because that method only ever enters with the
     * Serving purpose and plugin DDL needs Migrating (plan Ruling 6).
     */
    function pluginEnterTenantForMigration(string $tenant = 'tenant-a'): void
    {
        tenancy()->enter($tenant, TenantConnectionPurpose::Migrating);

        $connection = DB::connection(AccessTenantFixture::connectionName());

        if ($connection->getDriverName() === 'sqlite') {
            $connection->statement('pragma foreign_keys = on');
            $connection->statement('pragma synchronous = off');
        }
    }
}

if (! function_exists('pluginClockAt')) {
    function pluginClockAt(string $at): RunnerClock
    {
        $clock = RunnerClock::fixedAt($at);

        app()->instance(RunnerClock::class, $clock);

        return $clock;
    }
}

if (! function_exists('pluginRegisterFixtureTenant')) {
    /**
     * A master `tenants` row whose id is literally `tenant-a`/`tenant-b` -
     * the identifier `Tenancy::enter()` resolves against the kernel
     * fixture's own mapping, which is keyed by those two strings, never by
     * a random ULID a generic seeder would otherwise produce.
     */
    function pluginRegisterFixtureTenant(string $tenant = 'tenant-a'): string
    {
        $connection = DB::connection('master');

        if ($connection->table('tenants')->where('id', $tenant)->exists()) {
            return $tenant;
        }

        $connection->table('tenants')->insert([
            'id' => $tenant,
            'sequence' => $connection->table('tenants')->count() + 1,
            'code' => $tenant,
            'slug' => $tenant,
            'name' => 'Shop '.$tenant,
            'status' => 'active',
            'country' => 'LK',
            'currency' => 'LKR',
            'timezone' => 'Asia/Colombo',
            'business_type' => 'retail',
            'created_at' => '2026-01-01 00:00:00.000',
            'updated_at' => '2026-01-01 00:00:00.000',
        ]);

        return $tenant;
    }
}

if (! function_exists('pluginWriteManifest')) {
    /**
     * Write a temporary plugin package: `plugin.json` plus whatever
     * migration files are handed in, for the negative and isolation tests
     * that need a package distinct from `FixtureEcho`.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $migrationFiles  basename => file contents
     */
    function pluginWriteManifest(string $directory, array $manifest, array $migrationFiles = []): string
    {
        File::ensureDirectoryExists($directory.'/migrations');
        File::put($directory.'/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        foreach ($migrationFiles as $basename => $contents) {
            File::put($directory.'/migrations/'.$basename, $contents);
        }

        return $directory;
    }
}
