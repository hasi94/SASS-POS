<?php

use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrations;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsHandler;
use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineOnHold;
use Flexipos\Testing\Engine\ThreeTenantFixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TenantMigrations\FixtureMigrationLog;

/*
|--------------------------------------------------------------------------
| The execution path, against three real databases
|--------------------------------------------------------------------------
|
| T-008 step 11's engine half, and the only genuinely destructive step in the
| task. It waits behind gate G-1 and **nothing in this file is evidence for
| any [E] criterion while ENGINE_HOLD is set**: AC-38, AC-43's own-
| migrations-table half, AC-44's engine half and AC-52's literal wording are
| recorded NOT VERIFIED with the hold and the gate named.
|
| Every database it touches is created by ThreeTenantFixture through
| EngineDatabase, **by name**, in the same run, and dropped the same way in a
| finally. There is no sweep here and there must never be one.
|
| Red for this half is proven by **omitting the apply call** - a runner that
| plans, claims and closes the job without applying anything leaves FX-1's
| table absent in all three tenants - and never by pointing a working migrator
| at a database it did not create.
|
| The sqlite half of the same criteria - the status transitions, the mirror
| write, the cleared error, the eviction and the error sanitising - is proven
| without an engine in
| packages/kernel/tests/Schema/Features/MigrateTenant/MigrateTenantHandlerTest.php.
|
| **The positive control the observation rule requires** (plan section 14.2):
| wherever FixtureMigrationLog::observe() is used, the same file carries a run
| that *proceeds* and records a non-zero reading, because a refusal at plan
| time never runs up() at all and "the fixture saw nothing" would otherwise be
| indistinguishable from "the fixture never ran".
|
*/

/**
 * The fixture, the mapping and the published catalogue for one set.
 */
function migrateEngineBoot(MigrationSet $set): ThreeTenantFixture
{
    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);

    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));

    $fixture->mapInto(DB::connection('master'), MigrationSet::baseline(
        base_path((string) config('schema.tenant_migration_path'))
    )->highestVersion());

    DB::connection('master')->table('schema_versions')->insert(
        array_map(static fn (MigrationCatalogue $entry): array => $entry->toRow(), $set->catalogue())
    );

    FixtureMigrationLog::reset();

    $fixture->build();

    return $fixture;
}

it('refuses to reach the engine at all while the hold is set', function () {
    // The inverted case, so that in either state exactly one half of this file
    // is asserting something. The hold is fail-closed in code: the fixture
    // throws before it creates anything.
    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));

    expect(fn () => $fixture->build())->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('applies a later migration to every tenant of the fixture', function () {
    // AC-38: FX-1 is a table nobody had, supplied as a later migration, and
    // the runner's own code was not changed to allow it.
    $set = MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')))
        ->with('fx1', base_path('tests/Fixtures/TenantMigrations/FX1'));

    $fixture = migrateEngineBoot($set);

    try {
        $result = app(RunTenantMigrationsHandler::class)->handle(
            new RunTenantMigrations(set: $set, workerId: 'engine-worker')
        );

        expect($result->status)->toBe('completed')
            ->and($result->exitCode)->toBe(0);

        foreach ($fixture->databaseNames() as $database) {
            expect(EngineConnection::connection($database)->getSchemaBuilder()->hasTable('fx_projection_counts'))
                ->toBeTrue();
        }

        // The positive control for the observation instrument: FX-1's up() ran
        // and recorded a reading per tenant. Without this, a file in which the
        // probe recorded nothing would read the same as one in which it was
        // never installed.
        expect(FixtureMigrationLog::valuesFor('fx1.ran_on'))->toHaveCount(count($fixture->databaseNames()));
    } finally {
        $fixture->drop();
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('writes the tenant own migrations table and the master mirror together', function () {
    // AC-43(a) and (b): the tenant's own table is the authority and the
    // mapping is the mirror of it (D-14), so the two are read separately and
    // compared.
    $set = MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')))
        ->with('fx1', base_path('tests/Fixtures/TenantMigrations/FX1'));

    $fixture = migrateEngineBoot($set);

    try {
        app(RunTenantMigrationsHandler::class)->handle(
            new RunTenantMigrations(set: $set, workerId: 'engine-worker')
        );

        foreach (array_keys(ThreeTenantFixture::TENANTS) as $tenantId) {
            $database = $fixture->databaseNameFor($tenantId);

            $applied = EngineConnection::connection($database)->table('migrations')->count();

            expect($applied)->toBe($set->highestVersion());

            $mapping = (array) DB::connection('master')->table('tenant_databases')
                ->where('tenant_id', $tenantId)->first();

            expect((int) $mapping['schema_version'])->toBe($set->highestVersion())
                ->and($mapping['last_migrated_at'])->not->toBeNull()
                ->and($mapping['last_migration_error'])->toBeNull();
        }
    } finally {
        $fixture->drop();
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('fails one tenant and migrates the others anyway', function () {
    // AC-44 on the engine: FX-2 is told to fail on tenant C and the run still
    // finishes A and B.
    $set = MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')))
        ->with('fx1', base_path('tests/Fixtures/TenantMigrations/FX1'))
        ->with('fx2', base_path('tests/Fixtures/TenantMigrations/FX2'));

    $fixture = migrateEngineBoot($set);

    try {
        FixtureMigrationLog::failOnDatabase($fixture->databaseNameFor(ThreeTenantFixture::FAILING_TENANT));

        $result = app(RunTenantMigrationsHandler::class)->handle(
            new RunTenantMigrations(set: $set, workerId: 'engine-worker')
        );

        expect($result->status)->toBe('completed_with_failures')
            ->and($result->exitCode)->toBe(1);

        $failed = (array) DB::connection('master')->table('tenant_migration_jobs')
            ->where('tenant_id', ThreeTenantFixture::FAILING_TENANT)->first();

        expect((string) $failed['status'])->toBe('failed')
            ->and((string) $failed['error_migration'])->toContain('fx2');

        // The other two reached the target, which is the half a per-tenant
        // assertion alone would not give.
        foreach (array_keys(ThreeTenantFixture::TENANTS) as $tenantId) {
            if ($tenantId === ThreeTenantFixture::FAILING_TENANT) {
                continue;
            }

            expect(EngineConnection::connection($fixture->databaseNameFor($tenantId))
                ->getSchemaBuilder()->hasTable('fx_two_marker'))->toBeTrue();
        }
    } finally {
        $fixture->drop();
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('shows migrating from inside an offline migration and active afterwards', function () {
    // AC-52 keeps the criterion's literal wording (plan section 14.2): the
    // reading *is* the criterion, and the only observation point is inside
    // up(). FX-3 declares when the reading is taken; the probe that takes it
    // lives here, because the status is in the master and a migration naming
    // that connection would violate AC-37.
    $set = MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')))
        ->with('fx3', base_path('tests/Fixtures/TenantMigrations/FX3'));

    $fixture = migrateEngineBoot($set);

    try {
        FixtureMigrationLog::observeWith(static fn (string $event): mixed => DB::connection('master')
            ->table('tenant_databases')
            ->whereIn('tenant_id', array_keys(ThreeTenantFixture::TENANTS))
            ->where('status', 'migrating')
            ->count());

        app(RunTenantMigrationsHandler::class)->handle(
            new RunTenantMigrations(set: $set, workerId: 'engine-worker')
        );

        // Exactly one tenant is `migrating` at any moment inside up(), and the
        // reading is non-zero - which is the positive control the observation
        // rule demands beside every use of observe().
        expect(FixtureMigrationLog::valuesFor('fx3.status'))->toHaveCount(count(ThreeTenantFixture::TENANTS))
            ->and(array_unique(FixtureMigrationLog::valuesFor('fx3.status')))->toBe([1]);

        expect(DB::connection('master')->table('tenant_databases')->where('status', 'active')->count())
            ->toBe(count(ThreeTenantFixture::TENANTS));
    } finally {
        FixtureMigrationLog::observeWith(null);
        $fixture->drop();
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
