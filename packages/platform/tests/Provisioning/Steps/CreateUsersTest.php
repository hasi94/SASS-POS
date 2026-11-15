<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\RecordingTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\TenantGrant;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\WritableSecretStore;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\CreateUsers;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 10: create_users
|--------------------------------------------------------------------------
|
| AC-5 stand-in half, AC-15, AC-17.
*/

uses(TestCase::class);

function createUsersRoot(): string
{
    $root = sys_get_temp_dir().'/flexipos-t026-create-users-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}

function createUsersCleanup(string $root): void
{
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        @unlink($root.'/'.$entry);
    }

    @rmdir($root);
}

/**
 * @return array{0: RunContext, 1: StepJournal}
 */
function createUsersFixture(string $root): array
{
    $runId = app(ProvisioningRunner::class)->plan(
        'create',
        [],
        null,
        'worker-1',
        [StepName::ReserveIdentity, StepName::SelectServer, StepName::CreateDatabase, StepName::StoreCredentials, StepName::CreateUsers],
    );
    $context = new RunContext($runId, [], null);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity))->write([
        'tenant_id' => 'tenant-1',
        'sequence' => 1,
        'code' => 'TEN001',
        'database_name' => 'flx_t_000001',
        'app_username' => 'flx_t000001_app',
        'migrate_username' => 'flx_t000001_mig',
    ]);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::SelectServer))->write([
        'server_id' => 'srv1-id',
        'server_code' => 'srv1',
        'host' => '127.0.0.1',
        'port' => 3306,
        'engine' => 'mysql',
        'tls_mode' => 'required',
        'provisioner_credential_ref' => 'env://ADMIN',
    ]);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::CreateDatabase))->write([
        'database_name' => 'flx_t_000001',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $appRef = 'file://'.$root.'/tenant_000001_app';
    $migrateRef = 'file://'.$root.'/tenant_000001_mig';

    app(WritableSecretStore::class)->put($appRef, new Secret('app-password-1234567890123456789012'));
    app(WritableSecretStore::class)->put($migrateRef, new Secret('migrate-password-123456789012345678'));

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::StoreCredentials))->write([
        'app_credential_ref' => $appRef,
        'migrate_credential_ref' => $migrateRef,
    ]);

    $journal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::CreateUsers);

    return [$context, $journal];
}

beforeEach(function () {
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
    PlatformSeed::migrate();

    config(['tenancy.provisioning.driver' => 'recording']);
    app()->forgetInstance(TenantDatabaseProvisioner::class);
});

it('reads both passwords back through the resolver and creates both users with the right grants', function () {
    $root = createUsersRoot();

    try {
        config(['tenancy.secrets.file_root' => $root]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        [$context, $journal] = createUsersFixture($root);

        $output = app(CreateUsers::class)->run($context, $journal);

        expect($output['app_username'])->toBe('flx_t000001_app')
            ->and($output['migrate_username'])->toBe('flx_t000001_mig');

        /** @var RecordingTenantDatabaseProvisioner $provisioner */
        $provisioner = app(TenantDatabaseProvisioner::class);
        $calls = $provisioner->calls();

        $appCall = array_values(array_filter(
            $calls,
            fn (array $call): bool => $call['method'] === 'createUser' && $call['user'] === 'flx_t000001_app'
        ))[0] ?? null;
        $migrateCall = array_values(array_filter(
            $calls,
            fn (array $call): bool => $call['method'] === 'createUser' && $call['user'] === 'flx_t000001_mig'
        ))[0] ?? null;

        expect($appCall)->not->toBeNull()
            ->and($appCall['grant'])->toBe(TenantGrant::App->value)
            ->and($appCall['password_hash'])->toBe(hash('sha256', 'app-password-1234567890123456789012'))
            ->and($migrateCall)->not->toBeNull()
            ->and($migrateCall['grant'])->toBe(TenantGrant::Migrate->value)
            ->and($migrateCall['password_hash'])->toBe(hash('sha256', 'migrate-password-123456789012345678'));
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        createUsersCleanup($root);
    }
});

it('refuses a user that existed before this run\'s first attempt', function () {
    $root = createUsersRoot();

    try {
        config(['tenancy.secrets.file_root' => $root]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        [$context, $journal] = createUsersFixture($root);

        /** @var RecordingTenantDatabaseProvisioner $provisioner */
        $provisioner = app(TenantDatabaseProvisioner::class);
        $server = new ProvisioningServer('srv1', '127.0.0.1', 3306, 'mysql', 'required', 'env://ADMIN');
        // Simulate a foreign user that already existed before this run ever
        // attempted create_users.
        $provisioner->createUser($server, 'flx_t000001_app', new Secret('foreign-password-0000000000000000'), TenantGrant::App, 'flx_t_000001');

        expect(fn () => app(CreateUsers::class)->run($context, $journal))
            ->toThrow(PlatformRefused::class, 'flx_t000001_app');

        // Resume: refuses again, from the journalled outcome, with no second
        // call to userExists() needed.
        expect(fn () => app(CreateUsers::class)->run($context, $journal))
            ->toThrow(PlatformRefused::class, 'flx_t000001_app');
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        createUsersCleanup($root);
    }
});

it('is idempotent on resume: both users already created, the step completes with the same output', function () {
    $root = createUsersRoot();

    try {
        config(['tenancy.secrets.file_root' => $root]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        [$context, $journal] = createUsersFixture($root);

        $first = app(CreateUsers::class)->run($context, $journal);
        $second = app(CreateUsers::class)->run($context, $journal);

        expect($second)->toBe($first);
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        createUsersCleanup($root);
    }
});
