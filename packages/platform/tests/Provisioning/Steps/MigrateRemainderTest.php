<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\MigrateRemainder;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Fixtures\TenantMigrations\FixtureMigrationLog;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 11: migrate_remainder
|--------------------------------------------------------------------------
|
| AC-20 to AC-23. This step writes no migration-execution code of its own,
| so these tests drive it against T-008's own real planner/runner and a
| real SQLite tenant file - the same "no engine, real runner" shape
| QaRealApplierOnStandInTest already proves for T-008 itself - rather than
| faking the machinery this step only has to call correctly.
*/

uses(TestCase::class);

function migrateRemainderRoot(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'t026-migrate-remainder');
}

function migrateRemainderDatabasePath(string $tenantId): string
{
    return migrateRemainderRoot().DIRECTORY_SEPARATOR.$tenantId.'.sqlite';
}

function migrateRemainderBaselineSet(): MigrationSet
{
    return MigrationSet::baseline(base_path('tests/Fixtures/TenantMigrations/Baseline'));
}

beforeEach(function () {
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
    PlatformSeed::migrate();
    FixtureMigrationLog::reset();

    File::ensureDirectoryExists(migrateRemainderRoot());

    // The real master mapping reader, not the suite's in-memory config
    // stand-in: this step reaches a real tenant_databases row through
    // Tenancy::run(), exactly as it would in production.
    config(['tenancy.mapping.source' => 'master']);
    config(['schema.max_attempts' => 3]);
    config(['schema.claim_ttl_minutes' => 30]);
    config(['schema.per_server_limit' => 2]);
    app()->forgetInstance(TenantDatabaseRepository::class);

    putenv('T026_MIGRATE_REMAINDER_PASSWORD=m1grate-DO-NOT-LEAK-91bc');
    $_ENV['T026_MIGRATE_REMAINDER_PASSWORD'] = 'm1grate-DO-NOT-LEAK-91bc';
    $_SERVER['T026_MIGRATE_REMAINDER_PASSWORD'] = 'm1grate-DO-NOT-LEAK-91bc';
});

afterEach(function () {
    File::deleteDirectory(migrateRemainderRoot());
    FixtureMigrationLog::reset();
    putenv('T026_MIGRATE_REMAINDER_PASSWORD');
    unset($_ENV['T026_MIGRATE_REMAINDER_PASSWORD'], $_SERVER['T026_MIGRATE_REMAINDER_PASSWORD']);
});

/**
 * Publish a set's catalogue into `schema_versions` - the runner refuses to
 * plan against an unpublished migration (AC-32).
 */
function migrateRemainderPublish(MigrationSet $set): void
{
    DB::connection('master')->table('schema_versions')->insert(
        array_map(static fn ($entry): array => $entry->toRow(), $set->catalogue())
    );
}

/**
 * One `database_servers` row and one real SQLite tenant file mapped to it,
 * for a tenant reserved at `provisioning`.
 *
 * @return array{0: RunContext, 1: StepJournal, 2: string}
 */
function migrateRemainderFixture(string $code = 'TEN001'): array
{
    $tenantId = PlatformSeed::tenant($code, 'provisioning');

    $serverId = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    PlatformSeed::connection()->table('database_servers')->insert([
        'id' => $serverId,
        'code' => 'srv-'.strtolower($code),
        'name' => 'Server for '.$code,
        'host' => '127.0.0.1',
        'port' => 3306,
        'engine' => 'sqlite',
        'tls_mode' => 'disabled',
        'provisioner_credential_ref' => 'env://T026_MIGRATE_REMAINDER_PASSWORD',
        'tier' => 'shared',
        'max_tenants' => 100,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $path = migrateRemainderDatabasePath($tenantId);
    File::put($path, '');

    PlatformSeed::connection()->table('tenant_databases')->insert([
        'tenant_id' => $tenantId,
        'server_id' => $serverId,
        'database_identifier' => $code,
        'database_name' => $path,
        'app_username' => 'flx_'.strtolower($code).'_app',
        'app_credential_ref' => 'env://T026_MIGRATE_REMAINDER_PASSWORD',
        'migrate_username' => 'flx_'.strtolower($code).'_mig',
        'migrate_credential_ref' => 'env://T026_MIGRATE_REMAINDER_PASSWORD',
        'credential_version' => 1,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'status' => 'provisioning',
        'schema_version' => 0,
        'profile' => 'standard',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $runId = app(ProvisioningRunner::class)->plan(
        'create',
        [],
        null,
        'worker-1',
        [StepName::ReserveIdentity, StepName::MigrateRemainder],
    );
    $context = new RunContext($runId, [], null);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity))->write([
        'tenant_id' => $tenantId,
    ]);

    $journal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::MigrateRemainder);

    return [$context, $journal, $tenantId];
}

it('plans a named run for this tenant only, applies the baseline, and journals the run id and schema version (AC-20 to AC-23)', function () {
    config(['schema.tenant_migration_path' => 'tests/Fixtures/TenantMigrations/Baseline']);
    migrateRemainderPublish(migrateRemainderBaselineSet());

    // A second, already-active tenant exists in the fleet. A bulk plan
    // would select it too; a named plan (AC-20) must not touch it at all.
    [, , $bystanderId] = migrateRemainderFixture('TEN002');
    DB::connection('master')->table('tenant_databases')->where('tenant_id', $bystanderId)->update(['status' => 'active']);
    DB::connection('master')->table('tenants')->where('id', $bystanderId)->update(['status' => 'active']);

    [$context, $journal, $tenantId] = migrateRemainderFixture('TEN001');

    $output = app(MigrateRemainder::class)->run($context, $journal);

    expect($output['schema_version'])->toBe(migrateRemainderBaselineSet()->highestVersion())
        ->and($output['migration_run_id'])->toBeString();

    $jobs = DB::connection('master')->table('tenant_migration_jobs')
        ->where('run_id', $output['migration_run_id'])
        ->get();

    expect($jobs)->toHaveCount(1)
        ->and((string) $jobs->first()->tenant_id)->toBe($tenantId)
        ->and((string) $jobs->first()->status)->toBe('done');

    // The bystander was never planned (AC-20's named selector).
    expect(DB::connection('master')->table('tenant_migration_jobs')->where('tenant_id', $bystanderId)->count())->toBe(0);

    // The mapping status is still provisioning (AC-22): migrate_remainder
    // writes no status itself.
    $mapping = (array) DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->first();
    expect($mapping['status'])->toBe('provisioning')
        ->and((int) $mapping['schema_version'])->toBe(migrateRemainderBaselineSet()->highestVersion());
});

it('is idempotent on re-entry: the already-done job is not re-run and no second migration run is planned', function () {
    config(['schema.tenant_migration_path' => 'tests/Fixtures/TenantMigrations/Baseline']);
    migrateRemainderPublish(migrateRemainderBaselineSet());

    [$context, $journal] = migrateRemainderFixture();

    $first = app(MigrateRemainder::class)->run($context, $journal);
    $second = app(MigrateRemainder::class)->run($context, $journal);

    expect($second)->toBe($first)
        ->and(DB::connection('master')->table('tenant_migration_runs')->count())->toBe(1);
});

it('fails the step with the job\'s own error on a migration failure, and a resume after the fix completes on the same run id (AC-22, AC-23)', function () {
    config(['schema.tenant_migration_path' => 'tests/Fixtures/TenantMigrations/FX2']);
    $set = MigrationSet::baseline(base_path('tests/Fixtures/TenantMigrations/FX2'));
    migrateRemainderPublish($set);

    [$context, $journal, $tenantId] = migrateRemainderFixture();

    FixtureMigrationLog::failOnDatabase(migrateRemainderDatabasePath($tenantId));

    expect(fn () => app(MigrateRemainder::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'FX-2 was told to fail');

    $firstRunId = $journal->read()['migration_run_id'] ?? null;
    expect($firstRunId)->toBeString();

    $mapping = (array) DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->first();
    expect($mapping['status'])->toBe('provisioning');

    // Resume, after the operator has fixed whatever FX-2 was complaining
    // about: the same run id is reused (AC-23), never a second plan.
    FixtureMigrationLog::failOnDatabase(null);

    $output = app(MigrateRemainder::class)->run($context, $journal);

    expect($output['migration_run_id'])->toBe($firstRunId)
        ->and(DB::connection('master')->table('tenant_migration_runs')->count())->toBe(1);
});
