<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\DatabaseState;
use Flexipos\Kernel\Tenancy\Provisioning\RecordingTenantDatabaseProvisioner;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\CreateDatabase;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 10: create_database
|--------------------------------------------------------------------------
|
| AC-5 stand-in half, AC-15, AC-39.
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));

    // T-026 Ruling 4: a test binds the recording stand-in explicitly - the
    // default binding refuses, so forgetting this line fails closed instead
    // of reaching a live server.
    config(['tenancy.provisioning.driver' => 'recording']);
    app()->forgetInstance(TenantDatabaseProvisioner::class);
});

/**
 * @return array{0: RunContext, 1: StepJournal}
 */
function createDatabaseFixture(string $databaseName = 'flx_t_000001'): array
{
    $runId = app(ProvisioningRunner::class)->plan(
        'create',
        [],
        null,
        'worker-1',
        [StepName::ReserveIdentity, StepName::SelectServer, StepName::CreateDatabase],
    );
    $context = new RunContext($runId, [], null);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity))->write([
        'tenant_id' => (string) Str::ulid(),
        'sequence' => 1,
        'code' => 'TEN001',
        'database_name' => $databaseName,
        'app_username' => 'flx_t000001_app',
        'migrate_username' => 'flx_t000001_mig',
    ]);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::SelectServer))->write([
        'server_id' => (string) Str::ulid(),
        'server_code' => 'srv1',
        'host' => '127.0.0.1',
        'port' => 3306,
        'engine' => 'mysql',
        'tls_mode' => 'required',
        'provisioner_credential_ref' => 'env://ADMIN',
    ]);

    $journal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::CreateDatabase);

    return [$context, $journal];
}

it('creates the database when it is Absent on the first attempt', function () {
    [$context, $journal] = createDatabaseFixture();

    $output = app(CreateDatabase::class)->run($context, $journal);

    expect($output['database_name'])->toBe('flx_t_000001')
        ->and($output['charset'])->toBe('utf8mb4')
        ->and($output['collation'])->toBe('utf8mb4_unicode_ci');

    /** @var RecordingTenantDatabaseProvisioner $provisioner */
    $provisioner = app(TenantDatabaseProvisioner::class);
    $calls = array_filter($provisioner->calls(), fn (array $call): bool => $call['method'] === 'createDatabase');
    expect($calls)->toHaveCount(1);
});

it('refuses as pre-existing when Occupied on the first attempt, and refuses again on resume without adopting it', function () {
    /** @var RecordingTenantDatabaseProvisioner $provisioner */
    $provisioner = app(TenantDatabaseProvisioner::class);
    $provisioner->seedExistingDatabase('flx_t_000001', DatabaseState::Occupied);

    [$context, $journal] = createDatabaseFixture();

    expect(fn () => app(CreateDatabase::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'flx_t_000001');

    // Resume: the refusal is journalled, so a second attempt refuses again.
    expect(fn () => app(CreateDatabase::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'flx_t_000001');

    $createCalls = array_filter($provisioner->calls(), fn (array $call): bool => $call['method'] === 'createDatabase');
    expect($createCalls)->toBe([]);
});

it('refuses as pre-existing when Empty on the first attempt (a database that predates this run)', function () {
    /** @var RecordingTenantDatabaseProvisioner $provisioner */
    $provisioner = app(TenantDatabaseProvisioner::class);
    $provisioner->seedExistingDatabase('flx_t_000001', DatabaseState::Empty);

    [$context, $journal] = createDatabaseFixture();

    expect(fn () => app(CreateDatabase::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'flx_t_000001');
});

it('adopts Empty on re-entry after its own "about to create" marker was journalled (AC-5)', function () {
    [$context, $journal] = createDatabaseFixture();

    // Simulate a crash: the marker committed, but createDatabase() already
    // landed on a prior, uncommitted attempt - the state this run's own
    // earlier try left behind.
    $journal->write(['attempted' => true]);

    /** @var RecordingTenantDatabaseProvisioner $provisioner */
    $provisioner = app(TenantDatabaseProvisioner::class);
    $provisioner->seedExistingDatabase('flx_t_000001', DatabaseState::Empty);

    $output = app(CreateDatabase::class)->run($context, $journal);

    expect($output['database_name'])->toBe('flx_t_000001');

    $createCalls = array_filter($provisioner->calls(), fn (array $call): bool => $call['method'] === 'createDatabase');
    expect($createCalls)->toBe([]);
});

it('retries the create on re-entry when the marker was journalled but the effect never landed', function () {
    [$context, $journal] = createDatabaseFixture();
    $journal->write(['attempted' => true]);

    $output = app(CreateDatabase::class)->run($context, $journal);

    expect($output['database_name'])->toBe('flx_t_000001');

    /** @var RecordingTenantDatabaseProvisioner $provisioner */
    $provisioner = app(TenantDatabaseProvisioner::class);
    $createCalls = array_filter($provisioner->calls(), fn (array $call): bool => $call['method'] === 'createDatabase');
    expect($createCalls)->toHaveCount(1);
});

it('refuses Occupied on re-entry when a foreign write landed between the marker and this attempt', function () {
    [$context, $journal] = createDatabaseFixture();
    $journal->write(['attempted' => true]);

    /** @var RecordingTenantDatabaseProvisioner $provisioner */
    $provisioner = app(TenantDatabaseProvisioner::class);
    $provisioner->seedExistingDatabase('flx_t_000001', DatabaseState::Occupied);

    expect(fn () => app(CreateDatabase::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'flx_t_000001');
});
