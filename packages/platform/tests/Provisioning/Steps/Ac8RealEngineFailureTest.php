<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\DatabaseState;
use Flexipos\Kernel\Tenancy\Provisioning\RecordingTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\TenantGrant;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\WritableSecretStore;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningStepFailed;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\CreateDatabase;
use Flexipos\Platform\Provisioning\Steps\CreateUsers;
use Flexipos\Platform\Provisioning\Steps\ReserveIdentity;
use Flexipos\Platform\Provisioning\Steps\SelectServer;
use Flexipos\Platform\Provisioning\Steps\StoreCredentials;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 AC-8: a run that fails at create_database, store_credentials or
| create_users - before write_mapping - leaves no mapping row and the
| tenant not servable.
|--------------------------------------------------------------------------
|
| The two halves this task's own code is responsible for (no
| `tenant_databases` row; `tenants.status` stays `provisioning`) are proven
| directly, for each of the three steps AC-8 names. The "Tenancy::enter()
| refuses" and "a bulk PlanMigrationRun skips the tenant" halves are already
| proven, unchanged, by TenantStatusPolicy::NAMED_ONLY_STATUSES/
| Tenancy::SERVING_STATUSES' own suites (T-008/T-009) - AC-8's own text
| calls both "unchanged by this task".
*/

uses(TestCase::class);

/**
 * A provisioner that behaves exactly like the recording stand-in, except
 * that one named method throws before doing anything - the stand-in for "a
 * real engine step failed partway through".
 */
final class Ac8FailingProvisioner implements TenantDatabaseProvisioner
{
    public function __construct(
        private readonly RecordingTenantDatabaseProvisioner $inner,
        private readonly string $failingMethod,
    ) {}

    public function databaseState(ProvisioningServer $server, string $name): DatabaseState
    {
        return $this->maybeFail('databaseState', fn () => $this->inner->databaseState($server, $name));
    }

    public function createDatabase(ProvisioningServer $server, string $name, string $charset, string $collation): void
    {
        $this->maybeFail('createDatabase', fn () => $this->inner->createDatabase($server, $name, $charset, $collation));
    }

    public function userExists(ProvisioningServer $server, string $user): bool
    {
        return $this->maybeFail('userExists', fn () => $this->inner->userExists($server, $user));
    }

    public function createUser(ProvisioningServer $server, string $user, Secret $password, TenantGrant $grant, string $database): void
    {
        $this->maybeFail('createUser', fn () => $this->inner->createUser($server, $user, $password, $grant, $database));
    }

    public function dropUser(ProvisioningServer $server, string $user): void
    {
        $this->maybeFail('dropUser', fn () => $this->inner->dropUser($server, $user));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function maybeFail(string $method, callable $call): mixed
    {
        if ($method === $this->failingMethod) {
            throw new RuntimeException("Ac8FailingProvisioner: [{$method}] failed.");
        }

        return $call();
    }
}

function ac8FixtureRoot(): string
{
    $root = sys_get_temp_dir().'/flexipos-t026-ac8-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}

function ac8Cleanup(string $root): void
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
 * Runs `reserve_identity`, `select_server` and the three real-engine steps
 * through the real {@see ProvisioningRunner}, with `$failingMethod` making
 * one engine call fail - AC-8's own fencing: before `write_mapping` ever
 * runs.
 */
function ac8RunAndFail(string $failingMethod, string $codeSuffix): string
{
    $serverId = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();
    PlatformSeed::connection()->table('database_servers')->insert([
        'id' => $serverId,
        'code' => 'srv1',
        'name' => 'Server 1',
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

    app()->instance(
        TenantDatabaseProvisioner::class,
        new Ac8FailingProvisioner(new RecordingTenantDatabaseProvisioner, $failingMethod)
    );

    $input = [
        'code' => 'TEN'.$codeSuffix,
        'slug' => 'ten'.strtolower($codeSuffix),
        'name' => 'Ten Shop',
        'country' => 'LK',
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
        'business_type' => 'retail',
        'server_code' => 'srv1',
    ];

    $order = [
        StepName::ReserveIdentity,
        StepName::SelectServer,
        StepName::CreateDatabase,
        StepName::StoreCredentials,
        StepName::CreateUsers,
    ];

    $runner = app(ProvisioningRunner::class);
    $runId = $runner->plan('create', $input, null, 'worker-1', $order);

    $steps = [
        'reserve_identity' => app(ReserveIdentity::class),
        'select_server' => app(SelectServer::class),
        'create_database' => app(CreateDatabase::class),
        'store_credentials' => app(StoreCredentials::class),
        'create_users' => app(CreateUsers::class),
    ];

    $caught = null;

    try {
        $runner->run($runId, $steps, $input, null);
    } catch (ProvisioningStepFailed $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();

    return $runId;
}

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
    config(['tenancy.provisioning.driver' => 'recording']);
});

it('AC-8: a failure at create_database leaves no mapping row and the tenant still provisioning', function () {
    $root = ac8FixtureRoot();

    try {
        config(['tenancy.secrets.file_root' => $root]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        $runId = ac8RunAndFail('createDatabase', 'CDB');

        $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
        expect($run->status)->toBe('failed')
            ->and($run->current_step)->toBe('create_database');

        $tenant = PlatformSeed::connection()->table('tenants')->where('id', $run->tenant_id)->first();
        expect($tenant->status)->toBe('provisioning');

        expect(PlatformSeed::connection()->table('tenant_databases')->where('tenant_id', $run->tenant_id)->count())
            ->toBe(0);
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        ac8Cleanup($root);
    }
});

it('AC-8: a failure at store_credentials leaves no mapping row and the tenant still provisioning', function () {
    $root = ac8FixtureRoot();

    try {
        config(['tenancy.secrets.file_root' => $root]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        // store_credentials itself never calls the engine port; the failure
        // this run sees is injected on its own write path instead.
        app()->instance(WritableSecretStore::class, new class implements WritableSecretStore
        {
            public function put(string $reference, Secret $value): void
            {
                throw new RuntimeException('store_credentials: write refused.');
            }

            public function exists(string $reference): bool
            {
                return false;
            }

            public function forget(string $reference): void {}
        });

        $runId = ac8RunAndFail('__never__', 'SCR');

        $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
        expect($run->status)->toBe('failed')
            ->and($run->current_step)->toBe('store_credentials');

        $tenant = PlatformSeed::connection()->table('tenants')->where('id', $run->tenant_id)->first();
        expect($tenant->status)->toBe('provisioning');

        expect(PlatformSeed::connection()->table('tenant_databases')->where('tenant_id', $run->tenant_id)->count())
            ->toBe(0);
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        ac8Cleanup($root);
    }
});

it('AC-8: a failure at create_users leaves no mapping row and the tenant still provisioning', function () {
    $root = ac8FixtureRoot();

    try {
        config(['tenancy.secrets.file_root' => $root]);
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);

        $runId = ac8RunAndFail('createUser', 'CRU');

        $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $runId)->first();
        expect($run->status)->toBe('failed')
            ->and($run->current_step)->toBe('create_users');

        $tenant = PlatformSeed::connection()->table('tenants')->where('id', $run->tenant_id)->first();
        expect($tenant->status)->toBe('provisioning');

        expect(PlatformSeed::connection()->table('tenant_databases')->where('tenant_id', $run->tenant_id)->count())
            ->toBe(0);
    } finally {
        app()->forgetInstance(SecretResolver::class);
        app()->forgetInstance(WritableSecretStore::class);
        ac8Cleanup($root);
    }
});
