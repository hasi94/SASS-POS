<?php

use Flexipos\Auth\Features\Login\Login;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\WritableSecretStore;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\Organization\Support\ActivationIssuer;
use Flexipos\Platform\Features\ProvisionTenant\ProvisionTenant;
use Flexipos\Platform\Features\ProvisionTenant\ProvisionTenantHandler;
use Flexipos\Platform\Features\ProvisionTenant\ProvisionTenantResult;
use Flexipos\Platform\Provisioning\OwnerCredentialAnnouncement;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

/*
|--------------------------------------------------------------------------
| T-026 step 15: end to end
|--------------------------------------------------------------------------
|
| AC-38, AC-4 and AC-5 end to end, AC-31 end to end.
|
| This is the one test in the whole task that drives `tenant:provision`
| through every one of its eleven real step classes, against the real
| shipped tenant migration tree, rather than against a per-step fixture
| that journals the earlier steps' outputs by hand. It lives in the host's
| own test tree (not packages/platform/tests) because it needs
| Flexipos\Auth\Features\Login\LoginHandler for the login assertion, and
| HostTests is the one layer deptrac.yaml already grants both
| Package_Platform and Package_Auth to (Ruling 1: "no new layer, and
| deptrac.yaml is not edited").
|
| **The working directory matters here.** `create_database`'s stand-in
| (Ruling 4) touches a sqlite file named exactly the bare `database_name`
| in the process's current working directory, and
| `write_mapping`/`Tenancy` carry that same bare name forward with no
| prefix - Laravel's own SQLiteConnector resolves it with realpath(),
| which is relative to getcwd(). So every test below runs inside its own
| throwaway directory (the step 4 probe's own shape, isolated per test so
| two cases' `flx_t_000001` files can never collide) and restores the
| original cwd in `afterEach`, even on failure.
|
| ENGINE_HOLD holds throughout: `tenancy.provisioning.driver` is
| `recording`, never `mysql`.
*/

const E2E_CLOCK = '2026-11-07 10:00:00.000';

/**
 * A fresh throwaway directory this test owns as its cwd, so the recording
 * provisioner's relative sqlite files cannot collide with any other test.
 */
function e2eWorkingRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'flexipos-t026-e2e-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}

function e2eSecretsRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'flexipos-t026-e2e-secrets-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}

function e2eRemoveTree(string $root): void
{
    foreach (glob($root.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($root);
}

/**
 * This test's own working state, kept in a static rather than on `$this`
 * (Pest's `TestCase` has no such properties, and PHPStan is right to say
 * so - see packages/kernel's own precedent for the same shape).
 *
 * @return array{cwd: string, workingRoot: string, secretsRoot: string, serverId: string}
 */
function &e2eState(): array
{
    static $state = ['cwd' => '', 'workingRoot' => '', 'secretsRoot' => '', 'serverId' => ''];

    return $state;
}

beforeEach(function () {
    app()->instance(RunnerClock::class, RunnerClock::fixedAt(E2E_CLOCK));
    PlatformSeed::migrate();

    $state = &e2eState();
    $state['cwd'] = (string) getcwd();
    $state['workingRoot'] = e2eWorkingRoot();
    $state['secretsRoot'] = e2eSecretsRoot();
    chdir($state['workingRoot']);

    // T-026 Ruling 4: the default binding refuses, so a test has to bind
    // the recording stand-in explicitly. ENGINE_HOLD: never 'mysql' here.
    config(['tenancy.provisioning.driver' => 'recording']);
    app()->forgetInstance(TenantDatabaseProvisioner::class);

    // The real master mapping reader, exactly as production resolves a
    // tenant's database - not the suite's config stand-in.
    config(['tenancy.mapping.source' => 'master']);
    app()->forgetInstance(TenantDatabaseRepository::class);

    config(['tenancy.secrets.file_root' => $state['secretsRoot']]);
    app()->forgetInstance(SecretResolver::class);
    app()->forgetInstance(WritableSecretStore::class);

    config(['schema.max_attempts' => 3]);
    config(['schema.claim_ttl_minutes' => 30]);
    config(['schema.per_server_limit' => 2]);

    // The real shipped tenant tree's catalogue, published into this test's
    // fresh in-memory master - migrate_remainder plans against this, never
    // a scratch tree.
    expect(Artisan::call('schema:publish'))->toBe(0);

    $state['serverId'] = e2eDatabaseServer();

    // A signing key, for the login assertion.
    require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
    authFixtureInstallKey('t026-e2e-key');
});

afterEach(function () {
    app(Tenancy::class)->leave();
    $state = e2eState();
    chdir($state['cwd']);
    e2eRemoveTree($state['workingRoot']);
    e2eRemoveTree($state['secretsRoot']);
});

function e2eDatabaseServer(): string
{
    $id = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    PlatformSeed::connection()->table('database_servers')->insert([
        'id' => $id,
        'code' => 'e2e-srv',
        'name' => 'E2E server',
        'host' => '127.0.0.1',
        'port' => 3306,
        'engine' => 'sqlite',
        'tls_mode' => 'disabled',
        'provisioner_credential_ref' => 'env://ADMIN',
        'tier' => 'shared',
        'max_tenants' => 100,
        'tenant_count' => 0,
        'status' => 'active',
        'accepts_new_tenants' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function e2eCommand(string $code, array $overrides = []): ProvisionTenant
{
    $defaults = [
        'code' => $code,
        'name' => 'Shop '.$code,
        'country' => 'LK',
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
        'businessType' => 'retail',
        'ownerEmail' => strtolower($code).'-owner@example.com',
        'ownerName' => null,
        'planId' => PlatformSeed::plan(),
        'active' => false,
        'branchCode' => null,
        'branchName' => null,
        'serverCode' => null,
        'slug' => null,
        'actorUserId' => null,
        'resumeRunId' => null,
        'abandonRunId' => null,
    ];

    $merged = array_merge($defaults, $overrides);

    return new ProvisionTenant(
        code: $merged['code'],
        name: $merged['name'],
        country: $merged['country'],
        timezone: $merged['timezone'],
        currency: $merged['currency'],
        businessType: $merged['businessType'],
        ownerEmail: $merged['ownerEmail'],
        ownerName: $merged['ownerName'],
        planId: $merged['planId'],
        active: $merged['active'],
        branchCode: $merged['branchCode'],
        branchName: $merged['branchName'],
        serverCode: $merged['serverCode'],
        slug: $merged['slug'],
        actorUserId: $merged['actorUserId'],
        resumeRunId: $merged['resumeRunId'],
        abandonRunId: $merged['abandonRunId'],
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function e2eProvision(string $code, array $overrides = []): ProvisionTenantResult
{
    return app(ProvisionTenantHandler::class)->handle(e2eCommand($code, $overrides));
}

function e2eTenantId(string $code): string
{
    $id = PlatformSeed::connection()->table('tenants')->where('code', $code)->value('id');
    expect($id)->not->toBeNull();

    return (string) $id;
}

it('completes a fresh run end to end: database, schema, owner and activation', function () {
    $result = e2eProvision('TEN201');

    expect($result->status)->toBe('completed')
        ->and($result->ownerMode)->toBe('created')
        ->and($result->ownerPassword)->toBeString()
        ->and($result->ownerPassword)->not->toBe('');

    $tenantId = e2eTenantId('TEN201');

    $tenant = (array) PlatformSeed::connection()->table('tenants')->where('id', $tenantId)->first();
    expect($tenant['status'])->toBe('trial')
        ->and($tenant['activated_at'])->not->toBeNull();

    $mapping = (array) PlatformSeed::connection()->table('tenant_databases')->where('tenant_id', $tenantId)->first();
    expect($mapping['status'])->toBe('active')
        ->and((int) $mapping['schema_version'])->toBe(tenantMigrationFileCount());

    $server = (array) PlatformSeed::connection()->table('database_servers')->where('id', e2eState()['serverId'])->first();
    expect((int) $server['tenant_count'])->toBe(1);

    $subscription = PlatformSeed::connection()->table('subscriptions')->where('tenant_id', $tenantId)->first();
    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe('trial');

    $run = (array) PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $result->runId)->first();
    expect($run['status'])->toBe('completed')
        ->and($run['tenant_id'])->toBe($tenantId);

    $doneSteps = PlatformSeed::connection()->table('tenant_provisioning_steps')
        ->where('run_id', $result->runId)
        ->where('status', 'done')
        ->count();
    expect($doneSteps)->toBe(count(StepName::cases()));

    // The owner login, tenant-side.
    app(Tenancy::class)->run($tenantId, function () {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        $employee = $connection->table('employees')->where('code', 'OWNER')->first();
        expect($employee)->not->toBeNull();

        $branch = $connection->table('branches')->where('code', 'MAIN')->whereNull('deleted_at')->first();
        expect($branch)->not->toBeNull();
    }, TenantConnectionPurpose::Serving);

    // AC-38: ActivationIssuer::issue() inside the tenant returns tenant
    // part TEN201.
    $issued = app(Tenancy::class)->run($tenantId, function () {
        return app(ActivationIssuer::class)->issue();
    }, TenantConnectionPurpose::Serving);

    expect($issued['code'])->toStartWith('TEN201-');

    // Login: the owner signs in with the printed password, sees the tenant
    // as selectable, and must change their password.
    $login = authFixtureLoginHandler()->handle(new Login(
        email: strtolower('TEN201').'-owner@example.com',
        password: (string) $result->ownerPassword,
        ip: '203.0.113.9',
        userAgent: 'FlexiPOS/1.0',
        deviceId: 'till-e2e',
    ));

    expect($login->mustChangePassword)->toBeTrue();

    $tenantEntry = null;

    foreach ($login->tenants as $entry) {
        if ($entry['code'] === 'TEN201') {
            $tenantEntry = $entry;
        }
    }

    expect($tenantEntry)->not->toBeNull()
        ->and($tenantEntry['selectable'])->toBeTrue()
        ->and($tenantEntry['is_default'])->toBeTrue();
});

it('attaches a second shop to the same owner email without touching the first (D-30)', function () {
    $first = e2eProvision('TEN202', ['ownerEmail' => 'shared.owner@example.com']);
    $firstTenantId = e2eTenantId('TEN202');

    $userId = PlatformSeed::connection()->table('users')->where('email', 'shared.owner@example.com')->value('id');
    expect($userId)->not->toBeNull();

    $firstMembershipBefore = (array) PlatformSeed::connection()->table('tenant_users')
        ->where('tenant_id', $firstTenantId)
        ->where('user_id', $userId)
        ->first();

    $second = e2eProvision('TEN203', ['ownerEmail' => 'shared.owner@example.com']);

    expect($second->status)->toBe('completed')
        ->and($second->ownerMode)->toBe('attached')
        ->and($second->ownerPassword)->toBeNull();

    $firstMembershipAfter = (array) PlatformSeed::connection()->table('tenant_users')
        ->where('tenant_id', $firstTenantId)
        ->where('user_id', $userId)
        ->first();

    expect($firstMembershipAfter)->toBe($firstMembershipBefore);

    $login = authFixtureLoginHandler()->handle(new Login(
        email: 'shared.owner@example.com',
        password: (string) $first->ownerPassword,
        ip: '203.0.113.10',
        userAgent: 'FlexiPOS/1.0',
        deviceId: 'till-e2e-2',
    ));

    $codes = array_map(static fn (array $tenant): string => $tenant['code'], $login->tenants);

    expect($codes)->toContain('TEN202')
        ->and($codes)->toContain('TEN203');
});

/*
|--------------------------------------------------------------------------
| Crash-and-resume: a crash right after each of the eleven steps, then a
| resume, ends completed
|--------------------------------------------------------------------------
|
| The decorator below wraps whatever step class the container would
| otherwise build: it lets the real step run to completion (its journal
| writes committed exactly as they would be in production), and then
| throws - once - simulating a crash after the step's own effect landed but
| before the runner could mark it `done` and move on. A resumed attempt
| resolves the same decorated class again, finds the arm spent, and simply
| delegates to the real (by-then-idempotent) step. No production file is
| touched to make this possible: Container::extend() applies to any class
| name, bound or not.
*/
final class E2eCrashOnceStep implements ProvisioningStep
{
    public static bool $armed = false;

    public function __construct(private readonly ProvisioningStep $real) {}

    public function run(RunContext $context, StepJournal $journal): array
    {
        $output = $this->real->run($context, $journal);

        if (self::$armed) {
            self::$armed = false;

            throw new RuntimeException('T-026 step 15: simulated crash right after this step committed.');
        }

        return $output;
    }

    public function compensate(RunContext $context): ?string
    {
        return $this->real->compensate($context);
    }
}

dataset('every provisioning step', array_map(
    static fn (StepName $step): array => [$step->value],
    StepName::cases()
));

it('crashes right after %s, then resumes to a completed run', function (string $stepValue) {
    $step = StepName::from($stepValue);
    $stepClass = 'Flexipos\\Platform\\Provisioning\\Steps\\'.Str::studly($step->value);

    E2eCrashOnceStep::$armed = true;
    app()->extend($stepClass, static fn (ProvisioningStep $built): ProvisioningStep => new E2eCrashOnceStep($built));

    $code = 'TC'.strtoupper(substr(md5($stepValue), 0, 4));

    $caught = null;

    try {
        e2eProvision($code);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toContain('simulated crash');

    $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('type', 'create')->orderByDesc('created_at')->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('failed');

    // Resume: the decorator is still in place but its arm is spent, so the
    // real step completes on re-entry and the run finishes.
    $resumed = app(ProvisionTenantHandler::class)->handle(e2eCommand($code, [
        'resumeRunId' => (string) $run->id,
    ]));

    expect($resumed->status)->toBe('completed');

    $tenantId = e2eTenantId($code);

    $tenant = (array) PlatformSeed::connection()->table('tenants')->where('id', $tenantId)->first();
    expect($tenant['status'])->toBe('trial');

    $mapping = (array) PlatformSeed::connection()->table('tenant_databases')->where('tenant_id', $tenantId)->first();
    expect($mapping['status'])->toBe('active');

    expect(PlatformSeed::connection()->table('tenant_users')->where('tenant_id', $tenantId)->count())->toBe(1);

    app(Tenancy::class)->run($tenantId, function () {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));
        expect($connection->table('employees')->where('code', 'OWNER')->count())->toBe(1);
        expect($connection->table('branches')->whereNull('deleted_at')->count())->toBe(1);
    }, TenantConnectionPurpose::Serving);

    $doneSteps = PlatformSeed::connection()->table('tenant_provisioning_steps')
        ->where('run_id', $resumed->runId)
        ->where('status', 'done')
        ->count();
    expect($doneSteps)->toBe(count(StepName::cases()));
})->with('every provisioning step');

/*
|--------------------------------------------------------------------------
| Review round 1 R-1 / D-31: the password actually survives a real process
| restart, not only an in-process crash
|--------------------------------------------------------------------------
|
| Every other crash-and-resume case above relies on
| OwnerCredentialAnnouncement's singleton surviving in-process between the
| simulated crash and its resume - which is exactly how R-1 stayed hidden: a
| real process restart would never have that. This test forgets the
| singleton before resuming, as a real new process would start with nothing
| in it, and then proves the password it announces on resume is not merely
| present but actually works, by logging in with it through the real
| LoginHandler (the same pattern as this file's own first test, around its
| login assertion).
*/
it('recovers the owner password after create_owner crashes right after committing and the process is forgotten, then logs in with the rotated password (review round 1 R-1, D-31)', function () {
    $stepClass = 'Flexipos\\Platform\\Provisioning\\Steps\\CreateOwner';

    E2eCrashOnceStep::$armed = true;
    app()->extend($stepClass, static fn (ProvisioningStep $built): ProvisioningStep => new E2eCrashOnceStep($built));

    $code = 'TEN209';

    $caught = null;

    try {
        e2eProvision($code);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();

    $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('type', 'create')->orderByDesc('created_at')->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('failed');

    $createOwnerStep = PlatformSeed::connection()->table('tenant_provisioning_steps')
        ->where('run_id', $run->id)->where('step', 'create_owner')->first();
    expect($createOwnerStep->status)->toBe('failed');

    // The one step that actually matters here: simulate a genuinely new
    // process. Nothing survives this in a real restart either.
    app()->forgetInstance(OwnerCredentialAnnouncement::class);

    $resumed = app(ProvisionTenantHandler::class)->handle(e2eCommand($code, [
        'resumeRunId' => (string) $run->id,
    ]));

    expect($resumed->status)->toBe('completed')
        ->and($resumed->ownerMode)->toBe('rotated')
        ->and($resumed->ownerPassword)->toBeString()
        ->and($resumed->ownerPassword)->not->toBe('');

    $login = authFixtureLoginHandler()->handle(new Login(
        email: strtolower($code).'-owner@example.com',
        password: (string) $resumed->ownerPassword,
        ip: '203.0.113.11',
        userAgent: 'FlexiPOS/1.0',
        deviceId: 'till-e2e-r1',
    ));

    expect($login->mustChangePassword)->toBeTrue();

    $tenantEntry = null;

    foreach ($login->tenants as $entry) {
        if ($entry['code'] === $code) {
            $tenantEntry = $entry;
        }
    }

    expect($tenantEntry)->not->toBeNull();
});

it('prints the owner password before the error when a later step fails after create_owner already committed (review round 1 R-1)', function () {
    $stepClass = 'Flexipos\\Platform\\Provisioning\\Steps\\Verify';

    E2eCrashOnceStep::$armed = true;
    app()->extend($stepClass, static fn (ProvisioningStep $built): ProvisioningStep => new E2eCrashOnceStep($built));

    $code = 'TEN210';

    // migrate_remainder runs `php artisan migrate` as a nested Artisan call
    // (T-011's own precedent): Artisan::output() would return that nested
    // call's output, not tenant:provision's own, so the outer command's
    // output is captured explicitly instead.
    $buffer = new BufferedOutput;

    $exitCode = Artisan::call('tenant:provision', [
        '--code' => $code,
        '--name' => 'Shop '.$code,
        '--country' => 'LK',
        '--timezone' => 'Asia/Colombo',
        '--currency' => 'LKR',
        '--business-type' => 'retail',
        '--owner-email' => strtolower($code).'-owner@example.com',
        '--plan' => PlatformSeed::plan(),
    ], $buffer);

    expect($exitCode)->not->toBe(0);

    $output = $buffer->fetch();
    expect($output)->toContain('Owner login created. Initial password:')
        ->and($output)->toContain('simulated crash');
});
