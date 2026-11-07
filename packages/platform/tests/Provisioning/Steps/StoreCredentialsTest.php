<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Exceptions\SecretPathEscapesRoot;
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

it('journals both references before the config root can move, so a resume never derives new ones from a changed root', function () {
    $firstRoot = storeCredentialsRoot();
    $secondRoot = storeCredentialsRoot();

    try {
        config(['tenancy.secrets.file_root' => $firstRoot]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
        PlatformSeed::migrate();

        [$context, $journal] = storeCredentialsFixture();

        $first = app(StoreCredentials::class)->run($context, $journal);

        $expectedAppRef = TenantDatabaseNames::appCredentialRef(1, $firstRoot);
        $expectedMigrateRef = TenantDatabaseNames::migrateCredentialRef(1, $firstRoot);

        expect($first['app_credential_ref'])->toBe($expectedAppRef)
            ->and($first['migrate_credential_ref'])->toBe($expectedMigrateRef);

        // Simulate a redeploy between the failed attempt and the resume: the
        // config root moves to a brand-new, empty directory.
        config(['tenancy.secrets.file_root' => $secondRoot]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        // The step still reads the journalled references - the ones rooted
        // under $firstRoot - rather than recomputing a second pair under
        // $secondRoot. Root confinement (Ruling 3) is a security boundary
        // checked against whatever root is configured *now*, so re-entering
        // behind a genuinely moved root fails closed: no new file is ever
        // written under $secondRoot, so nothing is orphaned there either.
        // This is the fix: before it, a re-entry would have recomputed a
        // *different* pair of references under $secondRoot and silently
        // written a second password file, orphaning the first.
        expect(fn () => app(StoreCredentials::class)->run($context, $journal))
            ->toThrow(SecretPathEscapesRoot::class);

        expect(scandir($secondRoot))->toBe(['.', '..']);

        // Once the misconfiguration is corrected (the root is pointed back
        // at where this run's secrets actually live), a resume completes
        // using exactly the original references, and the original password
        // - never rotated.
        config(['tenancy.secrets.file_root' => $firstRoot]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        $resumed = app(StoreCredentials::class)->run($context, $journal);

        expect($resumed['app_credential_ref'])->toBe($expectedAppRef)
            ->and($resumed['migrate_credential_ref'])->toBe($expectedMigrateRef);

        $appPassword = app(SecretResolver::class)->resolve($expectedAppRef)->reveal();
        $migratePassword = app(SecretResolver::class)->resolve($expectedMigrateRef)->reveal();

        expect(strlen($appPassword))->toBe(40)
            ->and(strlen($migratePassword))->toBe(40);
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        storeCredentialsCleanup($firstRoot);
        storeCredentialsCleanup($secondRoot);
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
