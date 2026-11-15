<?php

declare(strict_types=1);

use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantQueueJob;
use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineOnHold;
use Flexipos\Testing\Engine\ThreeTenantFixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| T-022 AC-9 [ENGINE] - the real-migration half of the job sweep
|--------------------------------------------------------------------------
|
| Permanent, CI-gate-enforced (docs/specs/T-022, AC-25), and gated by
| ENGINE_HOLD/G-1 the same way every other file in this directory is - not
| by a --group/--exclude-group flag (D-2, N-3): this file spawns no child
| process, so it cannot join the `toolchain` group without breaking
| ToolchainGroupCoverageTest's registry-equals-spawners check.
|
| Tenant A starts empty (ThreeTenantFixture::EMPTY_TENANT); B and C already
| carry the baseline. One job, claimed for A, is dispatched through the
| real sync queue while B is left ambient with no leave() - the stale
| stanza a recycled worker leaves - and the assertion is that FX-1's table
| exists only in A's real database afterwards, never in B's or C's.
|
| NOT VERIFIED while ENGINE_HOLD/G-1 is set (the inverted case below is
| what is verified instead): see Flexipos\Testing\Engine\EngineOnHold.
*/

function qaJobSweepEngineSet(): MigrationSet
{
    return MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')))
        ->with('fx1', base_path('tests/Fixtures/TenantMigrations/FX1'));
}

function qaJobSweepEngineBoot(): ThreeTenantFixture
{
    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);

    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));

    $fixture->mapInto(DB::connection('master'), MigrationSet::baseline(
        base_path((string) config('schema.tenant_migration_path'))
    )->highestVersion());

    DB::connection('master')->table('schema_versions')->insert(
        array_map(static fn (MigrationCatalogue $entry): array => $entry->toRow(), qaJobSweepEngineSet()->catalogue())
    );

    $fixture->build();

    return $fixture;
}

it('refuses to reach the engine at all while the hold is set', function () {
    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));

    expect(fn () => $fixture->build())->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('AC-9 [ENGINE]: a recycled worker`s stale stanza still migrates only the job`s own tenant, against the real engine', function () {
    $fixture = qaJobSweepEngineBoot();

    try {
        $set = qaJobSweepEngineSet();
        $target = ThreeTenantFixture::EMPTY_TENANT;
        $ambient = array_keys(ThreeTenantFixture::TENANTS)[1];

        $runId = 'RUN00000000000000QAENGINE';
        $jobId = 'JOB00000000000000QAENGINE';

        DB::connection('master')->table('tenant_migration_runs')->insert([
            'id' => $runId,
            'target_version' => $set->highestVersion(),
            'mode' => 'bulk',
            'concurrency' => 1,
            'status' => 'planned',
            'total' => 1,
            'pending' => 1,
            'created_at' => '2026-10-15 09:00:00.000',
            'updated_at' => '2026-10-15 09:00:00.000',
        ]);

        DB::connection('master')->table('tenant_migration_jobs')->insert([
            'id' => $jobId,
            'run_id' => $runId,
            'tenant_id' => $target,
            'server_id' => '01J00000000000000000SERVER',
            'from_version' => 0,
            'to_version' => $set->highestVersion(),
            'status' => 'running',
            'attempts' => 1,
            'claimed_by' => 'qa-engine-worker',
            'claimed_at' => '2026-10-15 09:00:00.000',
            'lock_expires_at' => '2026-10-15 09:30:00.000',
            'started_at' => '2026-10-15 09:00:00.000',
        ]);

        // The stale stanza: tenant B is entered and the container scope is
        // dropped with no leave() at all - exactly what a recycled worker
        // leaves behind between jobs.
        tenancy()->enter($ambient);
        app()->forgetScopedInstances();

        Bus::dispatch(MigrateTenantQueueJob::forJob($runId, $jobId, $target, 'qa-engine-worker', $set, 'schema'));

        foreach (array_keys(ThreeTenantFixture::TENANTS) as $tenantId) {
            $database = $fixture->databaseNameFor($tenantId);
            $hasFx1 = EngineConnection::connection($database)->getSchemaBuilder()->hasTable('fx_projection_counts');

            if ($tenantId === $target) {
                expect($hasFx1)->toBeTrue("FX-1's table is missing from the job's own tenant [{$tenantId}].");
            } else {
                expect($hasFx1)->toBeFalse("FX-1's table leaked into tenant [{$tenantId}], which this job was never claimed for.");
            }
        }

        $jobRow = (array) DB::connection('master')->table('tenant_migration_jobs')->where('id', $jobId)->first();

        expect((string) $jobRow['status'])->toBe('done');
    } finally {
        $fixture->drop();
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
