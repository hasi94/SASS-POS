<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Provisioning\TenantDatabaseNames;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\WritableSecretStore;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\StoreCredentials;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 10: store_credentials
|--------------------------------------------------------------------------
|
| AC-15, AC-17.
*/

uses(TestCase::class);

function storeCredentialsRoot(): string
{
    $root = sys_get_temp_dir().'/flexipos-t026-store-credentials-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}

function storeCredentialsCleanup(string $root): void
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
function storeCredentialsFixture(int $sequence = 1): array
{
    $runId = app(ProvisioningRunner::class)->plan(
        'create',
        [],
        null,
        'worker-1',
        [StepName::ReserveIdentity, StepName::StoreCredentials],
    );
    $context = new RunContext($runId, [], null);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity))->write([
        'tenant_id' => 'tenant-'.$sequence,
        'sequence' => $sequence,
        'code' => 'TEN001',
        'database_name' => 'flx_t_000001',
        'app_username' => 'flx_t000001_app',
        'migrate_username' => 'flx_t000001_mig',
    ]);

    $journal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::StoreCredentials);

    return [$context, $journal];
}

it('generates and writes two distinct, high-entropy passwords (AC-15)', function () {
    $root = storeCredentialsRoot();

    try {
        config(['tenancy.secrets.file_root' => $root]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
        PlatformSeed::migrate();

        [$context, $journal] = storeCredentialsFixture();

        $output = app(StoreCredentials::class)->run($context, $journal);

        $expectedAppRef = TenantDatabaseNames::appCredentialRef(1, $root);
        $expectedMigrateRef = TenantDatabaseNames::migrateCredentialRef(1, $root);

        expect($output['app_credential_ref'])->toBe($expectedAppRef)
            ->and($output['migrate_credential_ref'])->toBe($expectedMigrateRef);

        $appPassword = app(SecretResolver::class)->resolve($expectedAppRef)->reveal();
        $migratePassword = app(SecretResolver::class)->resolve($expectedMigrateRef)->reveal();

        expect(strlen($appPassword))->toBe(40)
            ->and(strlen($migratePassword))->toBe(40)
            ->and($appPassword)->not->toBe($migratePassword);
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        storeCredentialsCleanup($root);
    }
});

it('AC-17 negative: re-entry neither rotates nor writes a second version', function () {
    $root = storeCredentialsRoot();

    try {
        config(['tenancy.secrets.file_root' => $root]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
        PlatformSeed::migrate();

        [$context, $journal] = storeCredentialsFixture();

        $first = app(StoreCredentials::class)->run($context, $journal);
        $firstAppPassword = app(SecretResolver::class)->resolve((string) $first['app_credential_ref'])->reveal();

        $second = app(StoreCredentials::class)->run($context, $journal);
        $secondAppPassword = app(SecretResolver::class)->resolve((string) $second['app_credential_ref'])->reveal();

        expect($second)->toBe($first)
            ->and($secondAppPassword)->toBe($firstAppPassword);
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        storeCredentialsCleanup($root);
    }
});
