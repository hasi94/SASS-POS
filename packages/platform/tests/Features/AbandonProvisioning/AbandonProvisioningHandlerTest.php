<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\AbandonProvisioning;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\RecordingTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\TenantGrant;
use Flexipos\Kernel\Tenancy\Secrets\CredentialGenerator;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\WritableSecretStore;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Features\AbandonProvisioning\AbandonProvisioning;
use Flexipos\Platform\Features\AbandonProvisioning\AbandonProvisioningHandler;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRunRefused;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\ReserveIdentity;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 16: abandon (Ruling 10)
|--------------------------------------------------------------------------
|
| AC-9, D-21; AC-39's "no drop" via check 1 (DestructiveStatementContainmentTest,
| which this file never touches - it only proves abandon's own writes never
| ask for one).
|
| Each fixture plays the same role the per-step fixtures already use
| (createDatabaseFixture, createUsersFixture, writeMappingFixture...): a
| run's steps are journalled directly, in D-20's order, as if the real step
| classes had already run that far and then crashed - exactly the state
| `AbandonProvisioningHandler` has to compensate. Nothing here binds the
| MySQL provisioner (Ruling 4, ENGINE_HOLD).
*/

uses(TestCase::class);

const ABANDON_CLOCK = '2026-11-07 10:00:00.000';

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt(ABANDON_CLOCK));

    config(['tenancy.provisioning.driver' => 'recording']);
    app()->forgetInstance(TenantDatabaseProvisioner::class);
});

/**
 * One run, journalled through write_mapping (reserve_identity,
 * select_server, create_database, store_credentials, create_users,
 * write_mapping all `done`), with a real tenant row, a real mapping row,
 * two real secret files and two users the recording provisioner really
 * knows about - then left `failed`, as `migrate_remainder` or any later
 * step crashing would leave it.
 *
 * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
 *                                                                      runId, tenantId, serverId, appCredentialRef, migrateCredentialRef
 */
function abandonFixtureThroughWriteMapping(string $code, string $secretsRoot): array
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
        'engine' => 'mysql',
        'tls_mode' => 'required',
        'provisioner_credential_ref' => 'env://ADMIN',
        'tier' => 'shared',
        'max_tenants' => 100,
        'tenant_count' => 0,
        'status' => 'active',
        'accepts_new_tenants' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $runId = app(ProvisioningRunner::class)->plan(
        'create',
        [],
        null,
        'worker-1',
        StepName::order(),
    );
    app(ProvisioningRuns::class)->setTenantId($runId, $tenantId, $now);
    app(ProvisioningRuns::class)->setTargetServerId($runId, $serverId, $now);

    [$databaseName, $appUser, $migrateUser] = abandonNamesFor($code);

    $reserveJournal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity);
    $reserveJournal->write([
        'tenant_id' => $tenantId,
        'sequence' => 1,
        'code' => $code,
        'database_name' => $databaseName,
        'app_username' => $appUser,
        'migrate_username' => $migrateUser,
    ]);
    app(ProvisioningRuns::class)->markStepDone($runId, StepName::ReserveIdentity->value, $reserveJournal->read(), $now);

    $selectJournal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::SelectServer);
    $selectJournal->write([
        'server_id' => $serverId,
        'server_code' => 'srv-'.strtolower($code),
        'host' => '127.0.0.1',
        'port' => 3306,
        'engine' => 'mysql',
        'tls_mode' => 'required',
        'provisioner_credential_ref' => 'env://ADMIN',
    ]);
    app(ProvisioningRuns::class)->markStepDone($runId, StepName::SelectServer->value, $selectJournal->read(), $now);

    $createDbJournal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::CreateDatabase);
    $createDbJournal->write([
        'database_name' => $databaseName,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);
    app(ProvisioningRuns::class)->markStepDone($runId, StepName::CreateDatabase->value, $createDbJournal->read(), $now);

    config(['tenancy.secrets.file_root' => $secretsRoot]);
    app()->forgetInstance(SecretResolver::class);
    app()->forgetInstance(WritableSecretStore::class);

    $appRef = 'file://'.$secretsRoot.'/'.$code.'_app';
    $migrateRef = 'file://'.$secretsRoot.'/'.$code.'_mig';

    app(WritableSecretStore::class)->put($appRef, CredentialGenerator::generate());
    app(WritableSecretStore::class)->put($migrateRef, CredentialGenerator::generate());

    $storeJournal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::StoreCredentials);
    $storeJournal->write([
        'app_credential_ref' => $appRef,
        'migrate_credential_ref' => $migrateRef,
    ]);
    app(ProvisioningRuns::class)->markStepDone($runId, StepName::StoreCredentials->value, $storeJournal->read(), $now);

    $server = new ProvisioningServer(
        'srv-'.strtolower($code), '127.0.0.1', 3306, 'mysql', 'required', 'env://ADMIN',
    );

    /** @var RecordingTenantDatabaseProvisioner $provisioner */
    $provisioner = app(TenantDatabaseProvisioner::class);
    $provisioner->createUser($server, $appUser, app(SecretResolver::class)->resolve($appRef), TenantGrant::App, $databaseName);
    $provisioner->createUser($server, $migrateUser, app(SecretResolver::class)->resolve($migrateRef), TenantGrant::Migrate, $databaseName);

    $createUsersJournal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::CreateUsers);
    $createUsersJournal->write(['checked' => true, 'app_username' => $appUser, 'migrate_username' => $migrateUser]);
    app(ProvisioningRuns::class)->markStepDone($runId, StepName::CreateUsers->value, $createUsersJournal->read(), $now);

    PlatformSeed::connection()->table('tenant_databases')->insert([
        'tenant_id' => $tenantId,
        'server_id' => $serverId,
        'database_identifier' => $code,
        'database_name' => $databaseName,
        'app_username' => $appUser,
        'app_credential_ref' => $appRef,
        'migrate_username' => $migrateUser,
        'migrate_credential_ref' => $migrateRef,
        'credential_version' => 1,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'status' => 'provisioning',
        'schema_version' => 0,
        'profile' => 'standard',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $writeMappingJournal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::WriteMapping);
    $writeMappingJournal->write(['database_name' => $databaseName]);
    app(ProvisioningRuns::class)->markStepDone($runId, StepName::WriteMapping->value, $writeMappingJournal->read(), $now);

    // Left exactly as a crash in migrate_remainder (or later) would leave
    // it: failed, with current_step naming whatever ran next.
    app(ProvisioningRuns::class)->markStepFailed($runId, StepName::MigrateRemainder->value, 'simulated crash', $now);
    app(ProvisioningRuns::class)->markRunFailed($runId, StepName::MigrateRemainder->value, 'simulated crash', $now);

    return [$runId, $tenantId, $serverId, $appRef, $migrateRef];
}

/**
 * @return array{0: string, 1: string, 2: string} databaseName, appUser, migrateUser
 */
function abandonNamesFor(string $code): array
{
    $suffix = substr(str_pad((string) crc32($code), 6, '0', STR_PAD_LEFT), -6);

    return ['flx_t_'.$suffix, 'flx_t'.$suffix.'_app', 'flx_t'.$suffix.'_mig'];
}

function abandonSecretsRoot(): string
{
    $root = sys_get_temp_dir().'/flexipos-t026-abandon-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}

function abandonCleanupRoot(string $root): void
{
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        @unlink($root.'/'.$entry);
    }

    @rmdir($root);
}

it('drops both database users, forgets both secrets, archives the tenant and the mapping, and reports the exact sentence (AC-9)', function () {
    $root = abandonSecretsRoot();

    try {
        [$runId, $tenantId, , $appRef, $migrateRef] = abandonFixtureThroughWriteMapping('TEN301', $root);
        [$databaseName, $appUser, $migrateUser] = abandonNamesFor('TEN301');

        $result = app(AbandonProvisioningHandler::class)->handle(new AbandonProvisioning($runId));

        expect($result->status)->toBe('rolled_back')
            ->and($result->databaseName)->toBe($databaseName)
            ->and($result->serverCode)->toBe('srv-ten301')
            ->and($result->message)->toContain('was not dropped')
            ->and($result->message)->toContain($databaseName)
            ->and($result->message)->toContain('srv-ten301');

        /** @var RecordingTenantDatabaseProvisioner $provisioner */
        $provisioner = app(TenantDatabaseProvisioner::class);
        $dropCalls = array_values(array_filter($provisioner->calls(), fn (array $call): bool => $call['method'] === 'dropUser'));
        $droppedUsers = array_map(static fn (array $call): string => (string) $call['user'], $dropCalls);

        expect($droppedUsers)->toContain($appUser)
            ->and($droppedUsers)->toContain($migrateUser);

        expect(app(WritableSecretStore::class)->exists($appRef))->toBeFalse()
            ->and(app(WritableSecretStore::class)->exists($migrateRef))->toBeFalse();

        $tenant = (array) PlatformSeed::connection()->table('tenants')->where('id', $tenantId)->first();
        expect($tenant['status'])->toBe('archived');

        $mapping = (array) PlatformSeed::connection()->table('tenant_databases')->where('tenant_id', $tenantId)->first();
        expect($mapping)->not->toBe([])
            ->and($mapping['status'])->toBe('archived')
            ->and($mapping['database_name'])->toBe($databaseName);

        $run = (array) PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
        expect($run['status'])->toBe('rolled_back');

        $doneBefore = [
            StepName::ReserveIdentity->value, StepName::SelectServer->value, StepName::CreateDatabase->value,
            StepName::StoreCredentials->value, StepName::CreateUsers->value, StepName::WriteMapping->value,
        ];

        foreach ($doneBefore as $step) {
            $row = app(ProvisioningRuns::class)->stepRow($runId, $step);
            expect($row['status'])->toBe('compensated');
        }

        // The step that actually failed is left failed, not relabelled.
        $failedStep = app(ProvisioningRuns::class)->stepRow($runId, StepName::MigrateRemainder->value);
        expect($failedStep['status'])->toBe('failed');
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        abandonCleanupRoot($root);
    }
});

it('abandons before write_mapping: only the tenant is archived, and there is no mapping row to archive', function () {
    $tenantId = PlatformSeed::tenant('TEN302', 'provisioning');
    $now = app(RunnerClock::class)->timestamp();

    $runId = app(ProvisioningRunner::class)->plan('create', [], null, 'worker-1', StepName::order());
    app(ProvisioningRuns::class)->setTenantId($runId, $tenantId, $now);

    $journal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity);
    $journal->write([
        'tenant_id' => $tenantId,
        'sequence' => 1,
        'code' => 'TEN302',
        'database_name' => 'flx_t_000302',
        'app_username' => 'flx_t000302_app',
        'migrate_username' => 'flx_t000302_mig',
    ]);
    app(ProvisioningRuns::class)->markStepDone($runId, StepName::ReserveIdentity->value, $journal->read(), $now);
    app(ProvisioningRuns::class)->markStepFailed($runId, StepName::SelectServer->value, 'simulated crash', $now);
    app(ProvisioningRuns::class)->markRunFailed($runId, StepName::SelectServer->value, 'simulated crash', $now);

    $result = app(AbandonProvisioningHandler::class)->handle(new AbandonProvisioning($runId));

    expect($result->status)->toBe('rolled_back')
        ->and($result->databaseName)->toBe('flx_t_000302')
        ->and($result->serverCode)->toBeNull();

    $tenant = (array) PlatformSeed::connection()->table('tenants')->where('id', $tenantId)->first();
    expect($tenant['status'])->toBe('archived');

    expect(PlatformSeed::connection()->table('tenant_databases')->where('tenant_id', $tenantId)->count())->toBe(0);
});

it('reports that there is no database to drop when the run never reached create_database', function () {
    $runId = app(ProvisioningRunner::class)->plan('create', [], null, 'worker-1', StepName::order());
    $now = app(RunnerClock::class)->timestamp();
    app(ProvisioningRuns::class)->markStepFailed($runId, StepName::ReserveIdentity->value, 'simulated crash', $now);
    app(ProvisioningRuns::class)->markRunFailed($runId, StepName::ReserveIdentity->value, 'simulated crash', $now);

    $result = app(AbandonProvisioningHandler::class)->handle(new AbandonProvisioning($runId));

    expect($result->databaseName)->toBeNull()
        ->and($result->message)->toContain('never reached create_database');

    expect(PlatformSeed::connection()->table('tenants')->count())->toBe(0);
});

it('refuses a completed run', function () {
    $runId = app(ProvisioningRunner::class)->plan('create', [], null, 'worker-1', StepName::order());
    $now = app(RunnerClock::class)->timestamp();
    app(ProvisioningRuns::class)->markRunCompleted($runId, $now);

    expect(fn () => app(AbandonProvisioningHandler::class)->handle(new AbandonProvisioning($runId)))
        ->toThrow(ProvisioningRunRefused::class);

    $run = (array) PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
    expect($run['status'])->toBe('completed');
});

it('refuses a non-stale running run', function () {
    $runId = app(ProvisioningRunner::class)->plan('create', [], null, 'worker-1', StepName::order());

    expect(fn () => app(AbandonProvisioningHandler::class)->handle(new AbandonProvisioning($runId)))
        ->toThrow(ProvisioningRunRefused::class);

    $run = (array) PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
    expect($run['status'])->toBe('running');
});

it('refuses an unknown run id', function () {
    expect(fn () => app(AbandonProvisioningHandler::class)->handle(new AbandonProvisioning('01J0000000000000000000000Z')))
        ->toThrow(ProvisioningRunRefused::class);
});

it('a fresh run with the same code is refused after abandon (the tenant row is archived, not deleted)', function () {
    $root = abandonSecretsRoot();

    try {
        [$runId] = abandonFixtureThroughWriteMapping('TEN303', $root);
        app(AbandonProvisioningHandler::class)->handle(new AbandonProvisioning($runId));

        $secondInput = [
            'code' => 'TEN303',
            'slug' => 'ten303',
            'name' => 'Ten Shop',
            'country' => 'LK',
            'timezone' => 'Asia/Colombo',
            'currency' => 'LKR',
            'business_type' => 'retail',
            'owner_email' => 'owner@example.test',
            'owner_name' => null,
            'plan_id' => null,
            'active' => false,
            'branch_code' => null,
            'branch_name' => null,
            'server_code' => null,
        ];
        $secondRunId = app(ProvisioningRunner::class)->plan('create', $secondInput, null, 'worker-2', [StepName::ReserveIdentity]);
        $context = new RunContext($secondRunId, $secondInput, null);
        $journal = new StepJournal(app(ProvisioningRuns::class), $secondRunId, StepName::ReserveIdentity);

        expect(fn () => app(ReserveIdentity::class)->run($context, $journal))
            ->toThrow(PlatformRefused::class, 'TEN303');
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        abandonCleanupRoot($root);
    }
});

it('leaves an attached owner\'s existing login completely untouched (D-30)', function () {
    $existingId = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    PlatformSeed::connection()->table('users')->insert([
        'id' => $existingId,
        'name' => 'Pre-existing Owner',
        'email' => 'already.a.login@example.com',
        'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$ZmFrZXNhbHQxMjM$ZmFrZWhhc2g',
        'status' => 'active',
        'must_change_password' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $before = (array) PlatformSeed::connection()->table('users')->where('id', $existingId)->first();

    $root = abandonSecretsRoot();

    try {
        [$runId, $tenantId] = abandonFixtureThroughWriteMapping('TEN304', $root);

        PlatformSeed::connection()->table('tenant_users')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'user_id' => $existingId,
            'access_level' => 'owner',
            'status' => 'active',
            'is_default' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app(AbandonProvisioningHandler::class)->handle(new AbandonProvisioning($runId));

        $after = (array) PlatformSeed::connection()->table('users')->where('id', $existingId)->first();
        expect($after)->toBe($before);

        // Abandon leaves the membership row alone too (Ruling 10's "it
        // leaves the employees, users and tenant_users rows alone").
        expect(PlatformSeed::connection()->table('tenant_users')->where('user_id', $existingId)->count())->toBe(1);
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        abandonCleanupRoot($root);
    }
});
